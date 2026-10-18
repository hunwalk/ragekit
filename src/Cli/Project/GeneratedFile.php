<?php

declare(strict_types=1);

namespace RageKit\Cli\Project;

/** Writes generated files without touching unchanged ones, so mtime-based up-to-date checks work. */
final class GeneratedFile
{
    /** @return bool whether the file was written (missing or different content) */
    public static function write(string $path, string $contents): bool
    {
        if (is_file($path) && file_get_contents($path) === $contents) {
            return false;
        }
        file_put_contents($path, $contents);
        return true;
    }
}
