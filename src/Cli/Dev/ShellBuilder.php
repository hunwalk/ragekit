<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Toolchain\NativeBuild;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\CppBuild;

/** Builds the dev shell (backend.yml `ragekit: dev: shell:`) when its sources or command changed. */
final class ShellBuilder
{
    /** @return array{path: string, built: bool} */
    public static function ensure(BuildContext $context, string $outDir): array
    {
        $shell = $context->backend->dev['shell'] ?? throw new RageKitException("dev mode needs the slint backend ({$context->backend->name} has no dev shell)");
        $paths = fn(array $list) => array_map(fn(string $path) => $context->backend->path($path), $list);
        $sources = SourceFiles::cpp($paths($shell['sources'] ?? []));
        $program = $context->toolchain->compiler()->program(new CppBuild(
            'ragekit-devshell',
            $outDir,
            $sources,
            $paths($shell['include-paths'] ?? []),
            $paths($shell['link-paths'] ?? []),
            $shell['link-libs'] ?? [],
            (string) ($shell['std'] ?? 'c++20'),
            $shell['defines'] ?? [],
        ));
        $built = NativeBuild::ensure($context, $program, $sources, 'building the dev shell (once per native change)', 'building the dev shell failed');
        foreach ($shell['copy'] ?? [] as $copy) {
            self::copyIfChanged($context->backend->path($copy['from']), dirname($program->output) . '/' . $copy['to']);
        }
        return ['path' => $program->output, 'built' => $built];
    }

    /** A running shell keeps its DLLs open (Windows won't overwrite them): copy only what changed. */
    private static function copyIfChanged(string $from, string $to): void
    {
        if (!is_file($from)) {
            throw new RageKitException("the dev shell needs $from, which is missing (run ragekit setup)");
        }
        if (is_file($to) && filesize($to) === filesize($from) && filemtime($to) >= filemtime($from)) {
            return;
        }
        if (!@copy($from, $to)) {
            throw new RageKitException("could not copy $from to $to");
        }
    }
}
