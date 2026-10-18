<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\RageKitException;

/**
 * Turns the app's `ragekit:` options into defines, as declared by the backend, e.g.
 * `renderer: software` + Slint's `define: RAGEKIT_SLINT_RENDERER_{VALUE}`.
 * `nano`, `kit`, `modules`, `assets` and `dev` are core options handled by ragekit itself.
 * Options the backend doesn't know are reported and ignored (an app may target several backends).
 */
final class ApplyOptions implements Step
{
    public function name(): string
    {
        return 'Apply options';
    }

    public function run(BuildContext $context): string
    {
        $declared = $context->backend->options;
        $applied = [];
        foreach ($context->project->options as $option => $value) {
            if ($option === 'nano') {
                if (!is_bool($value)) {
                    throw new RageKitException('nano must be true or false');
                }
                $applied[] = 'nano: ' . ($value ? 'on' : 'off');
                continue;
            }
            if ($option === 'kit') {
                $applied[] = "kit: $value";
                continue;
            }
            if (in_array($option, ['modules', 'assets', 'dev'], true)) {
                continue; // core options handled by their own steps (PrepareModules, CopyAssets, ragekit dev)
            }
            if (!isset($declared[$option])) {
                $applied[] = "<comment>$option ignored (not used by {$context->backend->name})</comment>";
                continue;
            }
            $value = (string) $value;
            $values = $declared[$option]['values'];
            if (!in_array($value, $values, true)) {
                throw new RageKitException("unknown $option '$value' (use " . implode(', ', $values) . ')');
            }
            $context->config['defines'][] = str_replace('{VALUE}', strtoupper($value), $declared[$option]['define']);
            $applied[] = "$option: $value";
        }
        return $applied === [] ? 'none' : implode(', ', $applied);
    }
}
