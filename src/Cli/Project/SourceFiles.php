<?php

declare(strict_types=1);

namespace RageKit\Cli\Project;

use RageKit\Cli\RageKitException;

/** Expands project.yml-style source lists (files and directories) into concrete files. */
final class SourceFiles
{
    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public static function php(array $paths): array
    {
        return array_values(array_filter(
            self::expand($paths, ['php']),
            fn(string $file) => !str_ends_with($file, '.stub.php'),
        ));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public static function cpp(array $paths): array
    {
        return self::expand($paths, ['cc', 'cpp', 'cxx']);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $extensions
     * @return list<string>
     */
    private static function expand(array $paths, array $extensions): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = Paths::normalize($path);
                continue;
            }
            if (!is_dir($path)) {
                throw new RageKitException("source path not found: $path");
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                    $files[] = Paths::normalize($file->getPathname());
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }
}
