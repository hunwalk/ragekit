<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Fetch\Dependency;
use RageKit\Cli\Fetch\Fetcher;
use RageKit\Cli\Modules\ModuleRegistry;
use RageKit\Cli\Templates\Kit;
use RageKit\Cli\Templates\KitRegistry;

/**
 * Third-party code the active kit and the app's modules declare under `fetch:` (e.g. Slint's
 * Material library, miniaudio): fetched when missing or not matching its checksum / commit.
 * Other kits are fetched only if the templates use them, when views are generated (fetchUsedKits).
 */
final class FetchDependencies implements Step
{
    public function name(): string
    {
        return 'Fetch dependencies';
    }

    public function run(BuildContext $context): string
    {
        $done = [];
        foreach (self::dependencies($context) as [$owner, $dependency]) {
            if (!Fetcher::present($dependency)) {
                $context->status->show("fetching $owner: {$dependency->describe()}");
            }
            $fetched = Fetcher::ensure($dependency);
            $done[] = "$owner " . ($fetched ? 'fetched' : 'ok');
        }
        return $done === [] ? 'none' : implode(', ', $done);
    }

    /**
     * The fetched code of the kits the compiled templates use (e.g. Material as the fallback for a
     * core element the active kit lacks, or a kit used through xmlns). $status shows what's fetched.
     *
     * @param list<Kit>                    $kits
     * @param (callable(string): void)|null $status
     * @return list<string> what was fetched, e.g. "material: …"
     */
    public static function fetchUsedKits(array $kits, ?callable $status = null): array
    {
        $fetched = [];
        foreach ($kits as $kit) {
            foreach ($kit->fetch as $dependency) {
                if (!Fetcher::present($dependency) && $status !== null) {
                    $status("fetching {$kit->name}: {$dependency->describe()}");
                }
                if (Fetcher::ensure($dependency)) {
                    $fetched[] = "{$kit->name}: {$dependency->describe()}";
                }
            }
        }
        return $fetched;
    }

    /**
     * The active kit's dependencies and those of the modules the app enables.
     *
     * @return list<array{0: string, 1: Dependency}> owner name, dependency
     */
    public static function dependencies(BuildContext $context): array
    {
        $root = $context->backend->rageKitRoot;
        $project = $context->project;
        $list = [];
        $kit = KitRegistry::discover($root, $project->dir)->get((string) ($project->options['kit'] ?? 'material'));
        foreach ($kit->fetch as $dependency) {
            $list[] = [$kit->name, $dependency];
        }
        $modules = $project->options['modules'] ?? [];
        if (is_array($modules) && $modules !== []) {
            $registry = ModuleRegistry::discover($root, $project->dir);
            foreach (array_unique(array_map('strval', $modules)) as $name) {
                foreach ($registry->get($name)->fetch as $dependency) {
                    $list[] = [$name, $dependency];
                }
            }
        }
        return $list;
    }
}
