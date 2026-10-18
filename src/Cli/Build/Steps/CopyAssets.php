<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\GeneratedFile;
use RageKit\Cli\RageKitException;

/**
 * `ragekit: assets: [...]`: files the app reads at runtime (sounds, images it loads itself),
 * copied next to the executable under the same relative paths — never embedded in it. Paths must
 * stay inside the project folder. The copied files are listed in the build folder's
 * `.ragekit-assets`, so files dropped from the list (or deleted) are removed on the next build;
 * files ragekit didn't copy are never touched.
 */
final class CopyAssets implements Step
{
    private const LIST = '.ragekit-assets';

    public function name(): string
    {
        return 'Copy assets';
    }

    public function run(BuildContext $context): string
    {
        $assets = $context->project->options['assets'] ?? [];
        if (!is_array($assets)) {
            throw new RageKitException('assets must be a list, e.g. assets: [assets/]');
        }
        $project = $context->project->dir;
        $out = $context->buildDir();
        $copied = [];
        foreach ($assets as $asset) {
            $relative = self::relative((string) $asset);
            $source = "$project/$relative";
            if (!file_exists($source)) {
                throw new RageKitException("asset not found: $asset");
            }
            foreach (is_dir($source) ? self::files($source) : [''] as $file) {
                $path = $file === '' ? $relative : "$relative/$file";
                $to = "$out/$path";
                @mkdir(dirname($to), 0777, true);
                GeneratedFile::write($to, (string) file_get_contents("$project/$path"));
                $copied[$path] = true;
            }
        }
        $removed = self::removeStale($out, array_keys($copied));
        $count = count($copied);
        $summary = $count === 0 ? 'none' : $count . ($count === 1 ? ' file' : ' files');
        return $summary . ($removed > 0 ? ", $removed removed" : '');
    }

    /** The asset path relative to the project, with `.` and empty segments dropped. */
    private static function relative(string $asset): string
    {
        $path = str_replace('\\', '/', $asset);
        $segments = array_values(array_filter(explode('/', $path), fn(string $s): bool => $s !== '' && $s !== '.'));
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1 || in_array('..', $segments, true) || $segments === []) {
            throw new RageKitException("asset paths must be inside the project folder: $asset");
        }
        return implode('/', $segments);
    }

    /**
     * Deletes the files the previous build copied that this one didn't, then folders left empty.
     *
     * @param list<string> $copied
     */
    private static function removeStale(string $out, array $copied): int
    {
        $list = "$out/" . self::LIST;
        $before = is_file($list) ? array_filter(explode("\n", (string) file_get_contents($list))) : [];
        $removed = 0;
        foreach (array_diff($before, $copied) as $path) {
            if (str_contains("/$path/", '/../') || !is_file("$out/$path")) {
                continue;
            }
            unlink("$out/$path");
            $removed++;
            $dir = dirname($path);
            while ($dir !== '.' && @rmdir("$out/$dir")) { // only succeeds on an empty folder
                $dir = dirname($dir);
            }
        }
        if ($copied === []) {
            @unlink($list);
        } else {
            @mkdir($out, 0777, true);
            GeneratedFile::write($list, implode("\n", $copied) . "\n");
        }
        return $removed;
    }

    /** @return list<string> files below $dir, relative to it */
    private static function files(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($dir) + 1);
            }
        }
        sort($files);
        return $files;
    }
}
