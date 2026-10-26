<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Package\PackageInfo;
use RageKit\Cli\Package\RuntimeFiles;
use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;
use RageKit\Tests\Support\InstalledToolchain;

/** `ragekit package`: what goes in, and the .deb / .tar.gz it makes (running them: by hand, see the notes). */
final class PackageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-package-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function project(string $ragekit): Project
    {
        file_put_contents("$this->dir/project.yml", "name: My_App\nragekit:\n  backend: slint\n$ragekit");
        return Project::load($this->dir);
    }

    public function testInfo(): void
    {
        $info = PackageInfo::of($this->project("  links: [myapp]\n  package:\n    version: 1.2.0~beta1\n    description: |\n      Summary\n      More.\n\n      Even more.\n    maintainer: Ann <ann@example.com>\n"));
        self::assertSame(['1.2.0~beta1', 'Ann <ann@example.com>', ['myapp'], 'my-app'], [$info->version, $info->maintainer, $info->links, $info->packageName()]);
        $defaults = PackageInfo::of($this->project(''));
        self::assertSame(['0.0.0', 'My_App, built with RageKit'], [$defaults->version, $defaults->description]);
    }

    public function testVersionMustStartWithADigit(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("version 'v1' must start with a digit");
        PackageInfo::of($this->project("  package: { version: v1 }\n"));
    }

    public function testRuntimeFilesLeaveTheBuildsOwnOut(): void
    {
        foreach (['app', 'libx.so', 'icon.png', 'assets/a.wav', 'tpc.yml', 'libx.so.cmd', '.ragekit-assets', 'cache/o.o', 'generated/app.slint', 'x.part'] as $file) {
            @mkdir(dirname("$this->dir/$file"), 0777, true);
            file_put_contents("$this->dir/$file", 'x');
        }
        self::assertSame(['app', 'assets/a.wav', 'icon.png', 'libx.so'], RuntimeFiles::list($this->dir));
    }

    public function testDebAndTar(): void
    {
        foreach (['dpkg-deb', 'dpkg-shlibdeps', 'fakeroot', 'tar'] as $tool) {
            if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec("command -v $tool")) === '') {
                self::markTestSkipped("needs Linux and $tool");
            }
        }
        $app = "$this->dir/links";
        exec('cp -r ' . escapeshellarg(self::ROOT . '/tests/fixtures/links') . ' ' . escapeshellarg($app));
        file_put_contents("$app/project.yml", "  package:\n    version: 2.0.1\n    description: |\n      A test app.\n      With links.\n", FILE_APPEND);
        $ragekit = 'cd ' . escapeshellarg($app) . ' && RAGEKIT_TOOLCHAIN=' . escapeshellarg(InstalledToolchain::dir()) . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(InstalledToolchain::root() . '/bin/ragekit') . ' package --format=';
        exec($ragekit . 'deb 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $deb = "$app/dist/links_2.0.1_" . trim((string) shell_exec('dpkg --print-architecture')) . '.deb';
        self::assertFileExists($deb);

        $control = (string) shell_exec('dpkg-deb -f ' . escapeshellarg($deb));
        self::assertStringContainsString("Package: links\nVersion: 2.0.1\n", $control);
        self::assertMatchesRegularExpression('/^Depends: .*libc6 \(>= /m', $control, 'from dpkg-shlibdeps');
        self::assertStringNotContainsString('libslint', $control, 'shipped libraries are not dependencies');
        self::assertStringContainsString("Description: A test app.\n With links.\n", $control);

        $list = (string) shell_exec('dpkg-deb -c ' . escapeshellarg($deb));
        self::assertMatchesRegularExpression('#^-rwxr-xr-x \S+ +\d+ \S+ \S+ \./opt/links/links$#m', $list);
        self::assertMatchesRegularExpression('#^-rw-r--r-- \S+ +\d+ \S+ \S+ \./opt/links/libslint_cpp\.so$#m', $list);
        self::assertMatchesRegularExpression('#\./usr/bin/links -> \.\./\.\./opt/links/links$#m', $list);
        self::assertStringNotContainsString('tpc.yml', $list);
        self::assertStringNotContainsString('/cache/', $list);

        $root = "$this->dir/deb";
        exec('dpkg-deb -x ' . escapeshellarg($deb) . ' ' . escapeshellarg($root));
        $desktop = (string) file_get_contents("$root/usr/share/applications/links.desktop");
        self::assertStringContainsString("Exec=/opt/links/links %u\n", $desktop);
        self::assertStringContainsString("Icon=/opt/links/icon.png\n", $desktop);
        self::assertStringContainsString("MimeType=x-scheme-handler/rktest;\n", $desktop, 'an installed app handles its links');
        if (trim((string) shell_exec('command -v desktop-file-validate')) !== '') {
            exec('desktop-file-validate ' . escapeshellarg("$root/usr/share/applications/links.desktop") . ' 2>&1', $problems, $valid);
            self::assertSame(0, $valid, implode("\n", $problems));
        }

        exec($ragekit . 'tar 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $tar = "$app/dist/links-2.0.1-linux-" . php_uname('m') . '.tar.gz';
        $entries = explode("\n", trim((string) shell_exec('tar -tzf ' . escapeshellarg($tar))));
        self::assertContains('links/links', $entries);
        self::assertContains('links/icon.png', $entries);
        self::assertNotContains('links/tpc.yml', $entries);
    }
}
