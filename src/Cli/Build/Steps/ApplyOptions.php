<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\RageKitException;

/**
 * Turns the app's `ragekit:` options into defines, as declared by the backend, e.g.
 * `renderer: software` + Slint's `define: RAGEKIT_SLINT_RENDERER_{VALUE}`.
 * `nano`, `kit`, `modules`, `assets`, `dev`, `links`, `icon` and `package` are core options handled by ragekit itself
 * (`links: [myapp]`: the URL schemes the app handles, as the define RAGEKIT_LINKS=myapp__other).
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
            if ($option === 'icon') {
                $applied[] = 'icon: ' . basename(\RageKit\Cli\Build\AppIcon::path($context->project));
                continue;
            }
            if ($option === 'links') {
                $schemes = self::schemes($value);
                $context->config['defines'][] = 'RAGEKIT_LINKS=' . implode('__', $schemes);
                $applied[] = 'links: ' . implode(', ', $schemes);
                continue;
            }
            if (in_array($option, ['modules', 'assets', 'dev', 'package'], true)) {
                continue; // core options handled by their own steps (PrepareModules, CopyAssets, ragekit dev, ragekit package)
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

    /** Schemes the OS hands to apps: RFC 3986's letters, digits, '+', '-', '.', minus the browser's own. @return list<string> */
    private static function schemes(mixed $value): array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new RageKitException('links must be a list of URL schemes, e.g. links: [myapp]');
        }
        $schemes = [];
        foreach ($value as $scheme) {
            $scheme = strtolower((string) $scheme);
            if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1) {
                throw new RageKitException("links: '$scheme' isn't a URL scheme (a letter, then letters, digits, '+', '-' or '.')");
            }
            if (in_array($scheme, ['http', 'https', 'file', 'mailto', 'ftp', 'data', 'javascript', 'about'], true)) {
                throw new RageKitException("links: '$scheme' belongs to the browser and the system; use your app's own scheme");
            }
            $schemes[] = $scheme;
        }
        return array_values(array_unique($schemes));
    }
}
