<?php

declare(strict_types=1);

namespace RageKit\Cli\Project;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\Platform;

/** Combines the app's project.yml with a backend's backend.yml into one tpc config. */
final class ConfigMerger
{
    private const PATH_LIST_KEYS = ['sources', 'ignore', 'include-paths', 'link-paths'];
    private const PATH_SCALAR_KEYS = ['manifest'];
    private const LIST_KEYS = ['sources', 'ignore', 'include-paths', 'link-paths', 'link-libs', 'defines', 'cxx-flags', 'ld-flags', 'c-flags'];

    /** Makes the path-like keys of a config fragment absolute, relative to $base. */
    public static function resolvePaths(array $config, string $base): array
    {
        foreach (self::PATH_LIST_KEYS as $key) {
            if (isset($config[$key])) {
                $config[$key] = array_map(fn($p) => Paths::absolute((string) $p, $base), (array) $config[$key]);
            }
        }
        foreach (self::PATH_SCALAR_KEYS as $key) {
            if (isset($config[$key])) {
                $config[$key] = Paths::absolute((string) $config[$key], $base);
            }
        }
        return $config;
    }

    /** App values win for scalars; lists are app entries followed by backend entries. */
    public static function merge(array $app, array $backend): array
    {
        $merged = $backend;
        foreach ($app as $key => $value) {
            if (in_array($key, self::LIST_KEYS, true)) {
                $merged[$key] = array_values(array_unique(array_merge((array) $value, (array) ($backend[$key] ?? []))));
            } else {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * Removes $raw's `platforms:` and merges in the entries for this platform: `<os>` (linux, windows,
     * macos), then `<os>-<arch>` (e.g. linux-x86_64). The same keys in backend.yml, module.yml and
     * toolchain.yml. $platform overrides the current `<os>-<arch>`; $where names the file in errors.
     */
    public static function forPlatform(array $raw, string $where, ?string $platform = null): array
    {
        $platforms = $raw['platforms'] ?? [];
        unset($raw['platforms']);
        if (!is_array($platforms)) {
            throw new RageKitException("$where: platforms must be a map");
        }
        foreach (array_keys($platforms) as $key) {
            if (preg_match('/^(linux|windows|macos)(-(x86_64|arm64))?$/', (string) $key) !== 1) {
                throw new RageKitException("$where: unknown platform '$key' (use linux, windows or macos, optionally with -x86_64 or -arm64)");
            }
        }
        $platform ??= Platform::key();
        $os = strtok($platform, '-');
        return self::overlay(self::overlay($raw, $platforms[$os] ?? []), $platforms[$platform] ?? []);
    }

    /** Deep merge for `platforms:` sections: lists appended, maps merged, scalars replaced. */
    public static function overlay(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $current = $base[$key] ?? null;
            if (is_array($value) && is_array($current)) {
                $base[$key] = array_is_list($value) && array_is_list($current)
                    ? array_merge($current, $value)
                    : self::overlay($current, $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Expands ${VAR} in all strings: the environment first, then $defaults.
     *
     * @param array<string, string> $defaults
     */
    public static function expandVars(mixed $value, array $defaults): mixed
    {
        if (is_array($value)) {
            return array_map(fn($v) => self::expandVars($v, $defaults), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/\$\{(\w+)\}/', function (array $m) use ($defaults): string {
            $env = getenv($m[1]);
            if ($env !== false && $env !== '') {
                return $env;
            }
            // Defaults may refer to other variables, e.g. SLINT_HOME: ${HOME}/tools/slint.
            return isset($defaults[$m[1]])
                ? (string) self::expandVars($defaults[$m[1]], $defaults)
                : throw new RageKitException("undefined variable \${$m[1]}");
        }, $value);
    }
}
