<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;

/** Starts the built app (with the TypePHP DLLs on PATH) and waits for it to exit. */
final class RunExecutable implements Step
{
    public function name(): string
    {
        return 'Run';
    }

    public function run(BuildContext $context): string
    {
        $exe = $context->toolchain->executable($context->outputBase());
        $context->exitCode = $context->toolchain->run([$exe], $context->project->dir);
        return $context->exitCode === 0 ? 'app exited' : "<comment>app exited with code {$context->exitCode}</comment>";
    }
}
