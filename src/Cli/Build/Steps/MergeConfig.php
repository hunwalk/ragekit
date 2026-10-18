<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\ConfigMerger;

/** project.yml + backend.yml → one tpc config with absolute paths. */
final class MergeConfig implements Step
{
    public function name(): string
    {
        return 'Merge config';
    }

    public function run(BuildContext $context): string
    {
        $project = $context->project;
        $backend = $context->backend;

        $config = ConfigMerger::merge(
            ConfigMerger::resolvePaths($project->config, $project->dir),
            ConfigMerger::resolvePaths($backend->config, $backend->rageKitRoot),
        );
        // Platform defaults the app didn't set (on Linux: the host PHP version, php-builder).
        $config += $context->toolchain->defaults($context->nano());
        $config['name'] = $project->name;
        $config['mode'] = $config['mode'] ?? 'bin';
        $context->config = $config;

        $sources = count($config['sources'] ?? []);
        $libs = count($config['link-libs'] ?? []);
        return "project.yml + {$backend->name}/backend.yml: $sources " . ($sources === 1 ? 'source' : 'sources')
            . ", $libs " . ($libs === 1 ? 'lib' : 'libs');
    }
}
