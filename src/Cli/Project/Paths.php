<?php

declare(strict_types=1);

namespace RageKit\Cli\Project;

use RageKit\Cli\RageKitException;

/** Path helpers. Paths handed to tpc always use forward slashes. */
final class Paths
{
    /** Resolves $path against $base (unless already absolute); the target must exist. */
    public static function absolute(string $path, string $base): string
    {
        $isAbsolute = preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1;
        $full = $isAbsolute ? $path : $base . DIRECTORY_SEPARATOR . $path;
        $real = realpath($full);
        if ($real === false) {
            throw new RageKitException("path not found: $full");
        }
        return self::normalize($real);
    }

    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function copyRecursive(string $from, string $to): void
    {
        if (is_file($from)) {
            @mkdir(dirname($to), 0777, true);
            copy($from, $to);
            return;
        }
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::copyRecursive("$from/$entry", "$to/$entry");
            }
        }
    }
}
