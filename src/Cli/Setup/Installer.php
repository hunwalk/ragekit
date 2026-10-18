<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

use RageKit\Cli\Fetch\Dependency;
use RageKit\Cli\Fetch\Fetcher;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\Process;

/**
 * Installs a Component into its folder: git checkout of the pinned commit, composer.lock +
 * `composer install`, downloads (checked, extracted), patches (`git apply`, must fit exactly).
 * A marker holding the component's fingerprint is written last, so an interrupted or failed
 * install never counts as installed and the next run starts over.
 */
final class Installer
{
    public const MARKER = '.ragekit-setup';

    public static function installed(Component $component, string $dir): bool
    {
        return @file_get_contents("$dir/" . self::MARKER) === $component->fingerprint() . "\n";
    }

    /** @return list<string> the commands install() runs for $component (checked before it starts) */
    public static function tools(Component $component): array
    {
        $tools = [];
        if ($component->git !== null) {
            $tools[] = 'git';
        }
        if ($component->composerLock !== null) {
            $tools[] = 'composer';
        }
        foreach ($component->downloads as $download) {
            $tools[] = str_ends_with(strtolower($download['url']), '.exe') ? '7z' : 'tar';
        }
        return array_values(array_unique($tools));
    }

    /**
     * @param callable(string): void $say progress lines
     * @return bool whether it was installed (false: already up to date)
     */
    public static function install(Component $component, string $dir, Process $process, callable $say): bool
    {
        if (self::installed($component, $dir)) {
            return false;
        }
        if ($component->patches !== [] && $component->git === null) {
            throw new RageKitException("toolchain.yml: {$component->name} has patches but no git checkout to apply them to");
        }
        Fetcher::remove($dir);
        @mkdir($dir, 0777, true);
        $run = function (array $command, string $what) use ($process, $dir): string {
            [$code, $output] = $process->capture($command, $dir);
            if ($code !== 0) {
                throw new RageKitException("$what failed:\n" . rtrim($output));
            }
            return $output;
        };

        if ($component->git !== null) {
            $say("{$component->name}: checking out " . substr((string) $component->commit, 0, 8) . " from {$component->git}");
            $run(['git', 'init', '-q'], 'git init');
            $run(['git', 'fetch', '-q', '--depth', '1', $component->git, (string) $component->commit], "fetching {$component->git}");
            $run(['git', 'checkout', '-q', 'FETCH_HEAD'], 'git checkout');
        }
        if ($component->composerLock !== null) {
            $say("{$component->name}: composer install");
            copy($component->composerLock, "$dir/composer.lock");
            $run(['composer', 'install', '--no-interaction', '--no-progress'], 'composer install');
        }
        foreach ($component->downloads as $download) {
            $say("{$component->name}: downloading " . basename($download['url']));
            self::download($download, $dir, $run);
        }
        foreach ($component->patches as $patch) {
            $say("{$component->name}: applying " . basename($patch));
            $run(['git', 'apply', '--check', $patch], basename($patch) . " doesn't fit {$component->name} " . $component->version . '; applying it');
            $run(['git', 'apply', $patch], 'applying ' . basename($patch));
        }
        file_put_contents("$dir/" . self::MARKER, $component->fingerprint() . "\n");
        return true;
    }

    /** @param array{url: string, sha256: string, to: string, strip: int} $download */
    private static function download(array $download, string $dir, callable $run): void
    {
        $file = basename(parse_url($download['url'], PHP_URL_PATH) ?: $download['url']);
        $dependency = Dependency::fromArray(['url' => $download['url'], 'sha256' => $download['sha256'], 'to' => ".download/$file"], $dir, 'toolchain.yml');
        Fetcher::ensure($dependency);
        $target = $download['to'] === '' ? $dir : "$dir/{$download['to']}";
        @mkdir($target, 0777, true);
        if (str_ends_with(strtolower($file), '.exe')) {
            // An installer we unpack instead of running (Slint's for Windows).
            $run(['7z', 'x', '-y', '-o' . $target, $dependency->target], "unpacking $file (needs 7-Zip on PATH)");
        } else {
            $command = ['tar', '-xf', $dependency->target, '-C', $target];
            if ($download['strip'] > 0) {
                $command[] = '--strip-components=' . $download['strip'];
            }
            $run($command, "unpacking $file");
        }
        Fetcher::remove("$dir/.download");
    }
}
