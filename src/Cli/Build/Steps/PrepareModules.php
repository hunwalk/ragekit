<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Modules\ModuleEmitter;
use RageKit\Cli\Modules\ModuleRegistry;
use RageKit\Cli\Project\GeneratedFile;
use RageKit\Cli\RageKitException;

/**
 * `ragekit: modules: [...]`: generates each module's glue (ModuleEmitter) and adds its PHP API and
 * host to the app; its library is built next to the executable but never linked — the app loads
 * it at runtime (see native/slint/bridge/rk_modules.cc).
 */
final class PrepareModules implements Step
{
    public function name(): string
    {
        return 'Prepare modules';
    }

    public function run(BuildContext $context): string
    {
        $names = $context->project->options['modules'] ?? [];
        if (!is_array($names)) {
            throw new RageKitException('modules must be a list, e.g. modules: [audio]');
        }
        $names = array_values(array_unique(array_map('strval', $names)));
        if ($names === []) {
            return 'none';
        }
        // Modules need runtime loading in the backend's bridge and a dev shell; only Slint has them.
        if ($context->backend->dev === []) {
            throw new RageKitException("modules need the slint backend (this app uses {$context->backend->name})");
        }
        $registry = ModuleRegistry::discover($context->backend->rageKitRoot, $context->project->dir);
        foreach ($names as $name) {
            $module = $registry->get((string) $name);
            $gen = $context->generatedDir() . "/modules/{$module->name}";
            @mkdir("$gen/host", 0777, true);
            @mkdir("$gen/lib", 0777, true);
            GeneratedFile::write("$gen/host/{$module->name}.stub.php", ModuleEmitter::stub($module));
            GeneratedFile::write("$gen/host/{$module->name}_host.cc", ModuleEmitter::host($module));
            GeneratedFile::write("$gen/lib/rk_module_{$module->name}.h", ModuleEmitter::header($module));
            GeneratedFile::write("$gen/lib/rk_module_{$module->name}_call.cc", ModuleEmitter::callEntry($module));

            foreach ($module->php as $path) {
                $context->config['sources'][] = $path;
            }
            $context->config['sources'][] = "$gen/host";
            $context->config['include-paths'][] = "$gen/lib";
            $context->moduleLibraries[] = [
                'name' => "rk_module_{$module->name}",
                'std' => 'c++17',
                'sources' => [...$module->library['sources'], "$gen/lib/rk_module_{$module->name}_call.cc"],
                'include-paths' => [...$module->library['include-paths'], "$gen/lib", $context->backend->path('native/include')],
                'link-paths' => $module->library['link-paths'],
                'link-libs' => $module->library['link-libs'],
            ];
        }
        return implode(', ', array_map('strval', $names));
    }
}
