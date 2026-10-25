<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Toolchain\Platform;

/**
 * Workarounds for tpc bugs (still in 0.9.5) — delete each one once a fixed tpc ships.
 */
final class TpcWorkarounds implements Step
{
    public function name(): string
    {
        return 'tpc workarounds';
    }

    public function run(BuildContext $context): string
    {
        $buildDir = $context->buildDir();
        $applied = [];

        // tpc doesn't recognise absolute Windows paths for `manifest` (its regex in Translator.php
        // escapes the `|`), so copy it next to the generated tpc.yml and use the file name.
        if (isset($context->config['manifest']) && Platform::os() !== 'windows') {
            unset($context->config['manifest']); // Windows-only (Common Controls v6, DPI awareness)
            $applied[] = 'manifest dropped (not Windows)';
        } elseif (isset($context->config['manifest'])) {
            copy($context->config['manifest'], "$buildDir/app.manifest");
            $context->config['manifest'] = 'app.manifest';
            $applied[] = 'manifest path';
        }
        return $applied === [] ? 'none needed' : implode(', ', $applied);
    }
}
