<?php

declare(strict_types=1);

namespace RageKit\Cli\Modules;

use RageKit\Cli\RageKitException;

/** Installed modules: RageKit's modules/*, the app's modules/*, Composer packages with extra.ragekit.module. */
final class ModuleRegistry
{
    /** @param array<string, Module> $modules */
    private function __construct(private readonly array $modules)
    {
    }

    public static function discover(string $rageKitRoot, string $projectDir): self
    {
        $files = [...(glob("$rageKitRoot/modules/*/module.yml") ?: []), ...(glob("$projectDir/modules/*/module.yml") ?: [])];
        $installed = "$projectDir/vendor/composer/installed.json";
        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
            foreach ($packages as $package) {
                $module = $package['extra']['ragekit']['module'] ?? null;
                if (is_string($module) && isset($package['install-path'])) {
                    $files[] = "$projectDir/vendor/composer/{$package['install-path']}/$module";
                }
            }
        }
        return self::load($files, $rageKitRoot);
    }

    /** @param list<string> $files module.yml paths */
    public static function load(array $files, string $rageKitRoot): self
    {
        $modules = [];
        foreach ($files as $file) {
            $module = Module::load($file, $rageKitRoot);
            if (isset($modules[$module->name]) && $modules[$module->name]->dir !== $module->dir) {
                throw new RageKitException("two modules are named {$module->name}: {$modules[$module->name]->dir} and {$module->dir}");
            }
            $modules[$module->name] = $module;
        }
        ksort($modules);
        return new self($modules);
    }

    public function get(string $name): Module
    {
        return $this->modules[$name]
            ?? throw new RageKitException("unknown module '$name' (installed: " . (implode(', ', $this->names()) ?: 'none') . ')');
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->modules);
    }
}
