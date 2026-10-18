<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\RageKitException;

/** Compiles the generated tpc.yml; tpc prints its own progress and errors. */
final class CompileWithTpc implements Step
{
    public function name(): string
    {
        return 'Compile with tpc';
    }

    public function run(BuildContext $context): string
    {
        $code = $context->exec([
            ...$context->toolchain->tpc(),
            $context->tpcConfigFile(),
            '--build-dir', $context->buildDir() . '/cache',
            '-o', $context->outputBase(),
            '--no-progress',
            ...($context->nano() ? ['--nano'] : []),
            ...$context->tpcArgs,
        ], $context->project->dir, 'tpc: compiling ' . $context->project->name . ($context->nano() ? ' (Nano)' : ''));
        if ($code !== 0) {
            throw new RageKitException("tpc failed (exit code $code)");
        }
        return $context->relative($context->toolchain->executable($context->outputBase()));
    }
}
