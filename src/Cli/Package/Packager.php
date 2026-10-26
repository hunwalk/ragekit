<?php

declare(strict_types=1);

namespace RageKit\Cli\Package;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\Platform;

/**
 * `ragekit package`: the built app (build/<backend>/) as a file to hand to people, in dist/.
 *   tar  <name>-<version>-linux-<arch>.tar.gz: a folder with the runtime files; run it from anywhere
 *        (the executable finds its libraries next to it).
 *   deb  <name>_<version>_<arch>.deb (Debian, Ubuntu): /opt/<name>, /usr/bin/<name>, a .desktop file
 *        with the icon and the app's link schemes (installed apps handle their links without
 *        Links::register()), Depends from dpkg-shlibdeps.
 */
final class Packager
{
    public function __construct(private readonly BuildContext $context)
    {
    }

    /** @return string the package file */
    public function package(string $format): string
    {
        if (Platform::isWindows()) {
            throw new RageKitException('packaging on Windows isn\'t supported yet');
        }
        $info = PackageInfo::of($this->context->project);
        $build = $this->context->buildDir();
        $files = RuntimeFiles::list($build);
        if (!in_array($this->context->project->name, $files, true)) {
            throw new RageKitException("no executable {$this->context->project->name} in $build: build the app first");
        }
        $dist = $this->context->project->dir . '/dist';
        @mkdir($dist, 0777, true);
        $stage = "$build/package";
        $this->remove($stage);
        return match ($format) {
            'tar' => $this->tar($info, $build, $files, $stage, $dist),
            'deb' => $this->deb($info, $build, $files, $stage, $dist),
            default => throw new RageKitException("unknown package format '$format' (tar, deb)"),
        };
    }

    /** @param list<string> $files */
    private function tar(PackageInfo $info, string $build, array $files, string $stage, string $dist): string
    {
        RuntimeFiles::copy($build, $files, "$stage/{$info->name}");
        $out = "$dist/{$info->name}-{$info->version}-linux-" . php_uname('m') . '.tar.gz';
        $this->run(['tar', '-czf', $out, '--owner=0', '--group=0', '-C', $stage, $info->name], $stage, 'tar');
        return $out;
    }

    /** @param list<string> $files */
    private function deb(PackageInfo $info, string $build, array $files, string $stage, string $dist): string
    {
        foreach (['dpkg-deb', 'dpkg-shlibdeps', 'fakeroot'] as $tool) {
            if (trim((string) shell_exec('command -v ' . escapeshellarg($tool))) === '') {
                throw new RageKitException("making a .deb needs $tool (Debian/Ubuntu: dpkg-dev, fakeroot)");
            }
        }
        $name = $info->packageName();
        $root = "$stage/deb";
        $opt = "$root/opt/{$info->name}";
        RuntimeFiles::copy($build, $files, $opt);
        @mkdir("$root/usr/bin", 0755, true);
        symlink("../../opt/{$info->name}/{$info->name}", "$root/usr/bin/{$info->name}");
        @mkdir("$root/usr/share/applications", 0755, true);
        file_put_contents("$root/usr/share/applications/$name.desktop", $this->desktopFile($info, $files));
        chmod("$root/usr/share/applications/$name.desktop", 0644);
        $arch = trim((string) shell_exec('dpkg --print-architecture'));
        $control = [
            'Package' => $name,
            'Version' => $info->version,
            'Architecture' => $arch !== '' ? $arch : 'amd64',
            'Maintainer' => $info->maintainer,
            'Installed-Size' => (string) (int) ceil($this->size($opt) / 1024),
            'Depends' => $this->depends($opt, $files),
            'Recommends' => $this->recommends(),
            'Section' => 'misc',
            'Priority' => 'optional',
            'Homepage' => $info->homepage,
            'Description' => self::description($info->description),
        ];
        @mkdir("$root/DEBIAN", 0755, true);
        $text = '';
        foreach ($control as $field => $value) {
            if ($value !== '') {
                $text .= "$field: $value\n";
            }
        }
        file_put_contents("$root/DEBIAN/control", $text);
        chmod("$root/DEBIAN/control", 0644);
        exec('find ' . escapeshellarg($root) . ' -type d -exec chmod 0755 {} +');
        $out = "$dist/{$name}_{$info->version}_{$control['Architecture']}.deb";
        $this->run(['fakeroot', 'dpkg-deb', '--build', '--root-owner-group', $root, $out], $stage, 'dpkg-deb');
        return $out;
    }

    /** @param list<string> $files */
    private function desktopFile(PackageInfo $info, array $files): string
    {
        $icon = in_array('icon.png', $files, true) ? 'icon.png' : (in_array('icon.svg', $files, true) ? 'icon.svg' : '');
        $lines = [
            '[Desktop Entry]',
            'Type=Application',
            "Name={$info->name}",
            'Comment=' . str_replace("\n", ' ', $info->description),
            "Exec=/opt/{$info->name}/{$info->name}" . ($info->links !== [] ? ' %u' : ''),
            "StartupWMClass={$info->name}",
            'Terminal=false',
            'Categories=Utility;',
        ];
        if ($icon !== '') {
            $lines[] = "Icon=/opt/{$info->name}/$icon";
        }
        if ($info->links !== []) {
            $lines[] = 'MimeType=' . implode('', array_map(fn(string $s) => "x-scheme-handler/$s;", $info->links));
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * The system libraries the binaries link (dpkg-shlibdeps), not the ones shipped next to them.
     *
     * @param list<string> $files
     */
    private function depends(string $opt, array $files): string
    {
        $elf = [];
        foreach ($files as $file) {
            $head = (string) @file_get_contents("$opt/$file", false, null, 0, 4);
            if ($head === "\x7fELF") {
                $elf[] = "$opt/$file";
            }
        }
        $work = $this->context->buildDir() . '/package/shlibdeps';
        @mkdir("$work/debian", 0755, true);
        file_put_contents("$work/debian/control", "Source: app\n\nPackage: app\nArchitecture: any\n");
        $command = 'cd ' . escapeshellarg($work) . ' && dpkg-shlibdeps -O --ignore-missing-info -l' . escapeshellarg($opt)
            . ' ' . implode(' ', array_map('escapeshellarg', $elf)) . ' 2>/dev/null';
        $out = (string) shell_exec($command);
        return preg_match('/^shlibs:Depends=(.*)$/m', $out, $m) === 1 ? trim($m[1]) : '';
    }

    /** Libraries modules (and the window code) load at run time, which dpkg-shlibdeps can't see. */
    private function recommends(): string
    {
        $modules = array_map('strval', (array) ($this->context->project->options['modules'] ?? []));
        $names = ['libx11-6'];
        if (in_array('keyring', $modules, true)) {
            $names[] = 'libsecret-1-0';
        }
        if (array_intersect(['notifications', 'tray'], $modules) !== []) {
            $names[] = 'libglib2.0-0t64 | libglib2.0-0';
        }
        if (in_array('http', $modules, true)) {
            $names[] = 'ca-certificates';
        }
        return implode(', ', $names);
    }

    /** A Debian description: the first line is the summary, further lines are indented by one space. */
    private static function description(string $text): string
    {
        $lines = explode("\n", trim($text));
        $summary = array_shift($lines);
        $rest = array_map(fn(string $l) => ' ' . (trim($l) === '' ? '.' : $l), $lines);
        return implode("\n", [$summary, ...$rest]);
    }

    private function size(string $dir): int
    {
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $total += $file->isFile() ? $file->getSize() : 0;
        }
        return $total;
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd, string $what): void
    {
        if ($this->context->exec($command, $cwd) !== 0) {
            throw new RageKitException("$what failed");
        }
    }

    private function remove(string $dir): void
    {
        if (is_dir($dir) || is_link($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
