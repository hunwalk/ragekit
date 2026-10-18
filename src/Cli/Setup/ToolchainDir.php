<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\Platform;

/**
 * Where `ragekit setup` installs the toolchain and where builds look for it, first match wins:
 *
 * 1. `RAGEKIT_TOOLCHAIN`, if set;
 * 2. the nearest `.ragekit/` folder from the project upward (a RageKit checkout keeps one in its
 *    root, which its demos share; any project can opt into its own);
 * 3. the per-user folder, one toolchain per set of pins: `<user dir>/toolchains/<pins fingerprint>`,
 *    so apps on the same RageKit version share one install and other versions don't overwrite it.
 */
final class ToolchainDir
{
    public const NAME = '.ragekit';
    private const LAST_USED = '.last-used';

    /** @param array<string, string>|null $env defaults to the process environment */
    public static function find(string $projectDir, Pins $pins, ?array $env = null): string
    {
        $env ??= getenv();
        $override = trim($env['RAGEKIT_TOOLCHAIN'] ?? '');
        if ($override !== '') {
            return rtrim(str_replace('\\', '/', $override), '/');
        }
        $current = rtrim($projectDir, '/\\');
        while (true) {
            if (is_dir($current . '/' . self::NAME)) {
                return $current . '/' . self::NAME;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return self::userDir(Platform::os(), $env) . '/toolchains/' . $pins->fingerprint();
            }
            $current = $parent;
        }
    }

    /**
     * The per-user toolchains installed for other pins (older or newer RageKit versions), which
     * `ragekit setup --prune` deletes. Toolchains in `.ragekit/` folders or RAGEKIT_TOOLCHAIN are
     * never listed.
     *
     * @param array<string, string>|null $env defaults to the process environment
     * @return list<string>
     */
    public static function others(Pins $pins, ?array $env = null): array
    {
        $parent = self::userDir(Platform::os(), $env ?? getenv()) . '/toolchains';
        $current = $pins->fingerprint();
        $dirs = [];
        foreach (glob("$parent/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== $current && preg_match('/^[0-9a-f]{12}$/', basename($dir)) === 1) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }

    /** Records that a build used $dir (shown by `ragekit setup --prune`). */
    public static function markUsed(string $dir): void
    {
        if (is_dir($dir)) {
            @touch("$dir/" . self::LAST_USED);
        }
    }

    /** When a build last used $dir, or null if none did (or before RageKit recorded it). */
    public static function lastUsed(string $dir): ?int
    {
        $time = @filemtime("$dir/" . self::LAST_USED);
        return $time === false ? null : $time;
    }

    /**
     * RageKit's per-user data folder: `$XDG_DATA_HOME/ragekit` or `~/.local/share/ragekit` (Linux),
     * `~/Library/Application Support/ragekit` (macOS), `%LOCALAPPDATA%\ragekit` (Windows).
     *
     * @param array<string, string> $env
     */
    public static function userDir(string $os, array $env): string
    {
        if ($os === 'windows') {
            $base = $env['LOCALAPPDATA'] ?? '';
            if ($base === '') {
                throw new RageKitException('no per-user folder for the toolchain: set LOCALAPPDATA or RAGEKIT_TOOLCHAIN');
            }
            return rtrim(str_replace('\\', '/', $base), '/') . '/ragekit';
        }
        $home = rtrim($env['HOME'] ?? '', '/');
        if ($home === '') {
            throw new RageKitException('no per-user folder for the toolchain: set HOME or RAGEKIT_TOOLCHAIN');
        }
        if ($os === 'macos') {
            return "$home/Library/Application Support/ragekit";
        }
        $data = rtrim($env['XDG_DATA_HOME'] ?? '', '/');
        return ($data !== '' ? $data : "$home/.local/share") . '/ragekit';
    }
}
