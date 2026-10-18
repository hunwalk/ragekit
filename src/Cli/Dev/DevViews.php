<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

use RageKit\Cli\Build\Steps\FetchDependencies;
use RageKit\Cli\Project\GeneratedFile;
use RageKit\Cli\Slint\Expression;
use RageKit\Cli\Slint\ScreensJson;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ViewProject;

/**
 * The dev shell's input: app.slint (compiled with the dev flag), one entry file per screen (the
 * interpreter builds one component per file), the used kits copied with their `@library`
 * imports made absolute (the interpreter's C++ API can't set library paths), and screens.json.
 */
final class DevViews
{
    public static function write(ViewProject $project, string $outDir): DevOutput
    {
        $compiled = (new SlintCompiler($project->kits, $project->activeKit, $project->projectDir, dev: true))->compile($project->classes);
        FetchDependencies::fetchUsedKits($project->kits->used($compiled->imports));
        @mkdir("$outDir/screens", 0777, true);
        $slint = $compiled->slint;
        $watch = [];
        $changed = false;
        foreach ($project->kits->names() as $name) {
            $kit = $project->kits->get($name);
            if (!in_array($kit->entry, $compiled->imports, true)) {
                continue;
            }
            $copy = "$outDir/kits/$name";
            foreach (self::files($kit->dir) as $relative) {
                $source = $kit->dir . '/' . $relative;
                $watch[] = $source;
                $contents = (string) file_get_contents($source);
                if (str_ends_with($relative, '.slint')) {
                    foreach ($kit->libraries as $library => $path) {
                        $contents = str_replace('"@' . $library . '"', Expression::slintString($path), $contents);
                    }
                }
                @mkdir(dirname("$copy/$relative"), 0777, true);
                $changed = GeneratedFile::write("$copy/$relative", $contents) || $changed;
            }
            $entry = $copy . substr($kit->entry, strlen($kit->dir));
            $slint = str_replace(Expression::slintString($kit->entry), Expression::slintString($entry), $slint);
        }
        $changed = GeneratedFile::write("$outDir/app.slint", $slint) || $changed;

        $entries = [];
        foreach ($compiled->screens as $screen) {
            $short = $screen->class->short;
            $entries[$short] = "$outDir/screens/$short.slint";
            $changed = GeneratedFile::write($entries[$short], "export { $short } from " . Expression::slintString("$outDir/app.slint") . ";\n") || $changed;
        }
        GeneratedFile::write("$outDir/screens.json", ScreensJson::emit($compiled->screens));

        foreach ($project->classes as $class) {
            $watch[] = $class->template;
        }
        return new DevOutput($entries, "$outDir/screens.json", array_values(array_unique([...$watch, ...$compiled->images])), $changed);
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
