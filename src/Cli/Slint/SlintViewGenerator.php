<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Steps\FetchDependencies;
use RageKit\Cli\Project\GeneratedFile;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Templates\ViewGenerator;
use RageKit\Cli\Templates\ViewProject;
use RageKit\Cli\Templates\ViewResult;

/**
 * Slint: app.slint (+ app.h via slint-compiler) and views_glue.cc for the bridge library,
 * RageKit\Generated\Screens for the app. backend.yml `views: slint-compiler:` names the compiler.
 */
final class SlintViewGenerator implements ViewGenerator
{
    public function generate(ViewProject $project, BuildContext $context): ViewResult
    {
        $compiled = (new SlintCompiler($project->kits, $project->activeKit, $project->projectDir))->compile($project->classes);
        $out = $project->outDir;
        @mkdir($out, 0777, true);
        GeneratedFile::write("$out/app.slint", $compiled->slint);
        GeneratedFile::write("$out/views_glue.cc", GlueEmitter::emit($compiled->screens));
        $binder = "$out/RageKit_Generated_Screens.php";
        GeneratedFile::write($binder, BinderEmitter::emit($compiled->screens));

        FetchDependencies::fetchUsedKits($project->kits->used($compiled->imports), $context->status->show(...));
        $inputs = $compiled->imports;
        foreach ($compiled->libraries as $path) {
            $inputs[] = $path;
        }
        if (!self::needsCompile("$out/app.h", "$out/app.slint", $inputs, $compiled->images)) {
            return $this->result($project, $compiled, $binder, true);
        }
        $compiler = $context->backend->views['slint-compiler'] ?? throw new RageKitException('backend.yml: views: slint-compiler is not set');
        if (!is_file($compiler)) {
            throw new RageKitException("slint-compiler not found at $compiler (run `ragekit setup`, or set SLINT_HOME)");
        }
        $command = [$compiler, "$out/app.slint", '-f', 'cpp', '--embed-resources=embed-files', '-o', "$out/app.h"];
        foreach ($compiled->libraries as $name => $path) {
            if (!file_exists($path)) {
                throw new RageKitException("Slint library '$name' not found at $path (the kit's fetch: in kit.yml provides it)");
            }
            array_push($command, '-L', "$name=$path");
        }
        if ($context->exec($command, $out, 'slint-compiler: app.slint → app.h') !== 0) {
            throw new RageKitException('slint-compiler rejected the generated build/' . $context->backend->name . '/generated/app.slint');
        }

        return $this->result($project, $compiled, $binder, false);
    }

    /**
     * slint-compiler has to run when app.h is missing or older than app.slint or than any input:
     * a kit/library .slint file, or any .slint file below the folder of one (they import each other).
     *
     * @param list<string> $inputs kit entry files and libraries (their folders are scanned for .slint)
     * @param list<string> $files  single files compared on their own, e.g. embedded images
     */
    public static function needsCompile(string $appHeader, string $appSlint, array $inputs, array $files = []): bool
    {
        if (!is_file($appHeader)) {
            return true;
        }
        clearstatcache();
        $built = filemtime($appHeader);
        $files = [$appSlint, ...$files];
        foreach ($inputs as $input) {
            $dir = is_dir($input) ? $input : dirname($input);
            $files[] = $input;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'slint') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) > $built) {
                return true;
            }
        }
        return false;
    }

    private function result(ViewProject $project, CompiledViews $compiled, string $binder, bool $cached): ViewResult
    {
        $screens = count($compiled->screens);
        $components = count($project->classes) - $screens;
        return new ViewResult([$binder], "$screens " . ($screens === 1 ? 'screen' : 'screens')
            . ", $components " . ($components === 1 ? 'component' : 'components') . ", kit {$project->activeKit}" . ($cached ? ' (unchanged)' : ''));
    }
}
