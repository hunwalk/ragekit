<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\Paths;

/** Puts the files the exe needs at runtime (DLLs, .slint libraries, …) next to it. */
final class CopyRuntimeFiles implements Step
{
    public function name(): string
    {
        return 'Copy runtime files';
    }

    public function run(BuildContext $context): string
    {
        $copied = [];
        foreach ($context->backend->copies as $copy) {
            $from = $context->backend->path($copy['from']);
            Paths::copyRecursive($from, $context->buildDir() . '/' . $copy['to']);
            $copied[] = $copy['to'] . (is_dir($from) ? '/' : '');
        }
        return $copied === [] ? 'nothing to copy' : implode(', ', $copied);
    }
}
