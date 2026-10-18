<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** The OS ragekit runs on, named like backend.yml / module.yml `platforms:` keys. */
final class Platform
{
    /** `windows`, `macos` or `linux`. */
    public static function os(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
    }

    /** `x86_64` or `arm64`. */
    public static function arch(): string
    {
        $machine = strtolower(php_uname('m'));
        return match ($machine) {
            'amd64', 'x64' => 'x86_64',
            'aarch64' => 'arm64',
            default => $machine,
        };
    }

    /** `<os>-<arch>`, e.g. linux-x86_64: the keys of toolchain.yml `platforms:`. */
    public static function key(): string
    {
        return self::os() . '-' . self::arch();
    }

    /** Where $command is on PATH, or null. */
    public static function onPath(string $command): ?string
    {
        $lookup = self::isWindows() ? 'where ' . escapeshellarg($command) . ' 2>NUL' : 'command -v ' . escapeshellarg($command) . ' 2>/dev/null';
        $found = trim((string) strtok((string) shell_exec($lookup), "\r\n"));
        return $found === '' ? null : $found;
    }

    public static function isWindows(): bool
    {
        return self::os() === 'windows';
    }
}
