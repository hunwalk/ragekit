<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Toolchain\NativeBuild;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;
use RageKit\Cli\Modules\ModuleRegistry;
use RageKit\Cli\Project\GeneratedFile;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\CppBuild;

/**
 * The app's native modules (`ragekit: modules:`) in dev mode: their PHP functions that call
 * into the dev shell, and their libraries, built into build/dev/modules/.
 */
final class DevModules
{
    /** @var array<string, Module> */
    private array $modules = [];

    /** @var array<string, string> module name => the library copy the shell loads */
    private array $libraries = [];

    /** numbers those copies (see build()) */
    private int $loadCounter = 0;

    public function __construct(private readonly BuildContext $context, private readonly string $devDir)
    {
    }

    public function prepare(): void
    {
        $names = $this->context->project->options['modules'] ?? [];
        if (!is_array($names)) {
            throw new RageKitException('modules must be a list, e.g. modules: [audio]');
        }
        $registry = ModuleRegistry::discover($this->context->backend->rageKitRoot, $this->context->project->dir);
        foreach (array_unique(array_map('strval', $names)) as $name) {
            $module = $registry->get($name);
            $this->modules[$module->name] = $module;
            @mkdir("$this->devDir/modules/{$module->name}", 0777, true);
            GeneratedFile::write($this->functionsFile($module->name), ModuleEmitter::devFunctions($module));
            $this->build($module->name);
        }
    }

    /**
     * Builds a module's library (when its sources changed) and returns the copy the shell should
     * load. Each build gets a fresh file name: glibc keeps a dlclose()d library mapped while it
     * has thread-local destructors pending, so dlopen() of the same path would hand back the
     * old code.
     */
    public function build(string $name): string
    {
        $module = $this->modules[$name];
        $gen = "$this->devDir/modules/$name/lib";
        @mkdir($gen, 0777, true);
        GeneratedFile::write("$gen/rk_module_$name.h", ModuleEmitter::header($module));
        GeneratedFile::write("$gen/rk_module_{$name}_call.cc", ModuleEmitter::callEntry($module));
        $sources = SourceFiles::cpp([...$module->library['sources'], "$gen/rk_module_{$name}_call.cc"]);
        $lib = $this->context->toolchain->compiler()->sharedLibrary(new CppBuild(
            "rk_module_$name",
            "$this->devDir/modules",
            $sources,
            [...$module->library['include-paths'], $gen, $this->context->backend->path('native/include')],
            $module->library['link-paths'],
            $module->library['link-libs'],
            'c++17',
        ));
        NativeBuild::ensure($this->context, $lib, $sources, "building the $name module", "building the $name module failed");

        $copy = sprintf('%s/modules/%s.%d', $this->devDir, basename($lib->output), ++$this->loadCounter);
        copy($lib->output, $copy);
        foreach (glob($this->devDir . '/modules/' . basename($lib->output) . '.*') ?: [] as $old) {
            if ($old !== $copy && !str_ends_with($old, '.cmd')) {
                @unlink($old);
            }
        }
        return $this->libraries[$name] = $copy;
    }

    /** @return list<string> the modules' PHP APIs and their dev functions */
    public function phpFiles(): array
    {
        $files = [];
        foreach ($this->modules as $name => $module) {
            $files = [...$files, ...SourceFiles::php($module->php), $this->functionsFile($name)];
        }
        return $files;
    }

    /** @return array<string, string> each library source => "module:<name>" (Watcher kinds) */
    public function watchList(): array
    {
        $list = [];
        foreach ($this->modules as $name => $module) {
            foreach ($module->library['sources'] as $source) {
                foreach (is_dir($source) ? (glob("$source/*") ?: []) : [$source] as $file) {
                    $list[$file] = "module:$name";
                }
            }
        }
        return $list;
    }

    /** @return list<string> --module name=path for each module */
    public function shellArguments(): array
    {
        $args = [];
        foreach ($this->libraries as $name => $library) {
            array_push($args, '--module', "$name=$library");
        }
        return $args;
    }

    private function functionsFile(string $name): string
    {
        return "$this->devDir/modules/$name/functions.php";
    }
}
