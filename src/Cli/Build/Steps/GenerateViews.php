<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\ConfigMerger;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Templates\ClassScanner;
use RageKit\Cli\Templates\KitRegistry;
use RageKit\Cli\Templates\ViewGenerator;
use RageKit\Cli\Templates\ViewProject;

/** .rk templates → backend code, through the generator backend.yml names (`views: generator:`). */
final class GenerateViews implements Step
{
    public function name(): string
    {
        return 'Generate views';
    }

    public function run(BuildContext $context): string
    {
        $generator = $context->backend->views['generator'] ?? null;
        if ($generator === null) {
            return 'none';
        }
        if (!class_exists($generator) || !is_subclass_of($generator, ViewGenerator::class)) {
            throw new RageKitException("backend.yml: views: generator $generator is not a " . ViewGenerator::class);
        }
        $result = (new $generator())->generate(self::viewProject($context, $context->generatedDir()), $context);
        foreach ($result->phpSources as $file) {
            $context->config['sources'][] = $file;
        }
        return $result->summary;
    }

    /** The app's components and templates, the installed kits and the active one (also used by `ragekit dev`). */
    public static function viewProject(BuildContext $context, string $outDir): ViewProject
    {
        $project = $context->project;
        $root = $context->backend->rageKitRoot;
        $appSources = ConfigMerger::resolvePaths(['sources' => $project->config['sources'] ?? []], $project->dir)['sources'];
        $classes = ClassScanner::scan(SourceFiles::php($appSources), $root, $project->dir);
        $kits = KitRegistry::discover($root, $project->dir);
        $kit = (string) ($project->options['kit'] ?? 'material');
        $kits->get($kit);
        return new ViewProject($classes, $kits, $kit, $outDir, $project->dir);
    }
}
