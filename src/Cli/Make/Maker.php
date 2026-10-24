<?php

declare(strict_types=1);

namespace RageKit\Cli\Make;

use RageKit\Cli\Dev\Bootstrap;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Stub;

/**
 * Writes the files of `ragekit make:<kind>` from stubs: the app's own (stubs/<name>, see
 * stub:publish) when there is one, else RageKit's (src/Cli/Make/stubs). Checks everything before
 * writing anything, and never overwrites a file unless $force.
 */
final class Maker
{
    public function __construct(private readonly string $rageKitRoot, private readonly AppPaths $app)
    {
    }

    /** The stubs make: uses, by file name (what stub:publish copies). @return list<string> */
    public static function stubNames(): array
    {
        $names = array_map('basename', glob(__DIR__ . '/stubs/*.stub') ?: []);
        sort($names);
        return $names;
    }

    public static function builtInStub(string $name): string
    {
        return __DIR__ . "/stubs/$name";
    }

    public function make(MakeKind $kind, string $name, bool $force = false): MadeFiles
    {
        $class = ClassName::parse($name, $kind);
        $relative = $class->relative($kind);
        $files = ["src/$relative.php" => "{$kind->value}.php.stub"];
        if ($kind->hasTemplate()) {
            $files["templates/$relative.rk"] = "{$kind->value}.rk.stub";
        }
        $this->checkUnique($class->short, "src/$relative.php");
        if (!$force) {
            foreach (array_keys($files) as $file) {
                if (file_exists("{$this->app->projectDir}/$file")) {
                    throw new RageKitException("$file already exists (--force overwrites it)");
                }
            }
        }
        $values = ['namespace' => $class->namespace($this->app->namespace, $kind), 'class' => $class->short];
        $contents = [];
        foreach ($files as $file => $stub) {
            // Templates have no namespace: only PHP stubs get it.
            $contents[$file] = $this->render($stub, str_ends_with($stub, '.rk.stub') ? ['class' => $values['class']] : $values);
        }
        $created = array_keys($files);
        $changed = [];
        $hints = self::hints($kind, $class->short, $values['namespace']);
        if ($kind === MakeKind::Store) {
            [$created, $changed, $hints] = $this->registerStore($class, $values['namespace'], $contents, $force);
        }
        foreach ($contents as $file => $content) {
            $this->write($file, $content);
        }
        return new MadeFiles($created, $changed, $hints);
    }

    /**
     * Adds the store to the app's RootStore (into $contents, written by the caller), or creates
     * src/Stores/AppStore.php with it when the app has none.
     *
     * @param array<string, string> $contents file => content, extended here
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} created, changed, hints
     */
    private function registerStore(ClassName $class, string $namespace, array &$contents, bool $force): array
    {
        $property = lcfirst($class->short);
        $created = array_keys($contents);
        $root = StoreRegistrar::findRoot($this->app->srcDir);
        if ($root === null) {
            $rootFile = 'src/Stores/AppStore.php';
            if (!$force && file_exists("{$this->app->projectDir}/$rootFile")) {
                throw new RageKitException("$rootFile already exists but doesn't extend RootStore (--force overwrites it)");
            }
            $rootNamespace = $this->app->namespace . '\\Stores';
            $type = $namespace === $rootNamespace ? $class->short : "\\$namespace\\{$class->short}";
            $contents[$rootFile] = $this->render('root-store.php.stub', [
                'namespace' => $rootNamespace,
                'class' => 'AppStore',
                'properties' => "public $type \$$property;",
                'assignments' => "\$this->$property = new $type();",
            ]);
            return [[...$created, $rootFile], [], ["return new \\$rootNamespace\\AppStore() from your App's createStore()"]];
        }
        [$file, $rootClass] = $root;
        $relative = substr(str_replace('\\', '/', $file), strlen($this->app->projectDir) + 1);
        $slash = strrpos($rootClass, '\\');
        $rootNamespace = $slash === false ? '' : substr($rootClass, 0, $slash);
        $type = $namespace === $rootNamespace ? $class->short : "\\$namespace\\{$class->short}";
        $contents[$relative] = StoreRegistrar::register((string) file_get_contents($file), $relative, $type, $property, $class->short, substr($rootClass, (int) $slash + ($slash === false ? 0 : 1)));
        return [$created, [$relative], ["templates read it as \$store->{$property}->…, PHP as " . substr($rootClass, (int) $slash + ($slash === false ? 0 : 1)) . "::get()->$property"]];
    }

    /** @param array<string, string> $values */
    private function render(string $stub, array $values): string
    {
        $own = "{$this->app->projectDir}/stubs/$stub";
        return is_file($own) ? Stub::render($own, $values, strict: false) : Stub::render(self::builtInStub($stub), $values);
    }

    private function write(string $file, string $content): void
    {
        $path = "{$this->app->projectDir}/$file";
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            throw new RageKitException('could not create ' . dirname($file));
        }
        if (file_put_contents($path, $content) === false) {
            throw new RageKitException("could not write $file");
        }
    }

    /** The build rejects two classes with one short name, so make: refuses it up front. */
    private function checkUnique(string $short, string $target): void
    {
        foreach (Bootstrap::classes(SourceFiles::php([$this->app->srcDir])) as $fqcn => $path) {
            $slash = strrpos($fqcn, '\\');
            $existing = $slash === false ? $fqcn : substr($fqcn, $slash + 1);
            $relative = substr(str_replace('\\', '/', $path), strlen($this->app->projectDir) + 1);
            if ($existing === $short && $relative !== $target) {
                throw new RageKitException("a class named $short already exists ($fqcn in $relative); class names must be unique");
            }
        }
    }

    /** @return list<string> */
    private static function hints(MakeKind $kind, string $short, string $namespace): array
    {
        return match ($kind) {
            MakeKind::Screen => ["show it: return new $short(); from your App's firstScreen(), or \$this->navigator()->push(new $short()) in a handler"],
            MakeKind::Window => ["use it: public static function window(): string { return $short::class; } in a screen"],
            MakeKind::Component => ["use it in a template: <$short />"],
            MakeKind::Flow => ["run it from a handler: $short::run();"],
            MakeKind::Assets => ["register it in your App: protected function assets(): array { return ['" . lcfirst($short) . "' => \\$namespace\\$short::class]; }"],
            default => [],
        };
    }
}
