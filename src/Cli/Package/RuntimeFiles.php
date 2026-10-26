<?php

declare(strict_types=1);

namespace RageKit\Cli\Package;

/** What of a build folder an app needs at run time: everything but the build's own files. */
final class RuntimeFiles
{
    /** Top-level entries that only the build uses. */
    private const BUILD_ONLY = ['cache', 'generated', 'dev', 'tpc.yml', 'package'];

    /** @return list<string> paths relative to $dir, sorted */
    public static function list(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr((string) $file->getPathname(), strlen($dir) + 1);
            $top = explode('/', str_replace('\\', '/', $path))[0];
            if (in_array($top, self::BUILD_ONLY, true) || str_starts_with(basename($path), '.') || str_ends_with($path, '.cmd')
                || str_ends_with($path, '.part') || $file->isDir()) {
                continue;
            }
            $files[] = str_replace('\\', '/', $path);
        }
        sort($files);
        return $files;
    }

    /** Copies $files from $from into $to (created): programs 0755, libraries and data 0644. */
    public static function copy(string $from, array $files, string $to): void
    {
        foreach ($files as $file) {
            @mkdir(dirname("$to/$file"), 0755, true);
            copy("$from/$file", "$to/$file");
            $library = preg_match('/\.(so(\.\d+)*|dll)$/', $file) === 1;
            chmod("$to/$file", is_executable("$from/$file") && !$library ? 0755 : 0644);
        }
    }
}
