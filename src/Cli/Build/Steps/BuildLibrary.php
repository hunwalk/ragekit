<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Toolchain\NativeBuild;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\Toolchain\CppBuild;
use RageKit\Cli\Toolchain\Platform;

/**
 * Builds backend.yml `libraries:` (e.g. the Slint bridge) into build/<backend>/ and links the app
 * against them by full path (TypePHP Nano forbids -l/-L), plus module libraries
 * (PrepareModules), which are only put next to the executable. Skipped while up to date.
 */
final class BuildLibrary implements Step
{
    public function name(): string
    {
        return 'Build libraries';
    }

    public function run(BuildContext $context): string
    {
        $done = [];
        $libraries = [];
        foreach ($context->backend->libraries as $library) {
            $libraries[] = [$library, true];
        }
        foreach ($context->moduleLibraries as $library) {
            $libraries[] = [$library, false]; // loaded at runtime, not linked
        }
        foreach ($libraries as [$library, $link]) {
            $paths = fn(array $list) => array_map(fn(string $path) => $this->path($path, $context), $list);
            $sources = SourceFiles::cpp($paths($library['sources'] ?? []));
            $lib = $context->toolchain->compiler()->sharedLibrary(new CppBuild(
                (string) $library['name'],
                $context->buildDir(),
                $sources,
                $paths($library['include-paths'] ?? []),
                $paths($library['link-paths'] ?? []),
                $library['link-libs'] ?? [],
                (string) ($library['std'] ?? 'c++17'),
            ));
            $status = basename($lib->command[0]) . ': ' . implode(', ', array_map('basename', $sources)) . ' → ' . basename($lib->output);
            $built = NativeBuild::ensure($context, $lib, $sources, $status, "building library {$library['name']} failed");
            if ($link && Platform::isWindows()) {
                $context->config['link-libs'][] = $lib->link;
            } elseif ($link) {
                $context->config['ld-flags'][] = escapeshellarg($lib->link);
            }
            $state = $built ? 'built' : 'up to date';
            $done[] = basename($lib->output) . " ($state)";
        }
        return $done === [] ? 'none' : implode(', ', $done);
    }

    private function path(string $path, BuildContext $context): string
    {
        if (str_starts_with($path, '@generated')) {
            return $context->generatedDir() . substr($path, strlen('@generated'));
        }
        return $context->backend->path($path);
    }
}
