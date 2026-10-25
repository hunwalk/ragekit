<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\ScreensJson;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

/** @foreach over Collection subclasses: one Slint struct per item class, $item->member. */
final class CollectionCompileTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-coll-' . uniqid();
        mkdir("$this->dir/assets", 0777, true);
        file_put_contents("$this->dir/assets/logo.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));
    }

    /** A collection of $of whose items have Device's members. */
    private function collection(string $name = 'Demo\Devices', string $of = 'Demo\Device', bool $found = true): ClassInfo
    {
        $short = substr($name, strrpos($name, '\\') + 1);
        return new ClassInfo($name, $short, "$this->dir/$short.php", false, '', isCollection: true, of: $of, ofFound: $found,
            props: $found ? ['name' => 'string', 'image' => 'string', 'battery' => 'float', 'online' => 'bool', 'count' => 'int', 'tags' => 'list', 'nick' => '?string'] : [],
            computed: $found ? ['label' => 'string', 'rename' => 'void'] : []);
    }

    /** @param array<string, string> $props */
    private function screen(string $rk, array $props = ['devices' => 'Demo\Devices'], string $short = 'Home', array $handlers = []): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk", props: $props, handlers: $handlers);
    }

    /** @param list<ClassInfo> $classes */
    private function compile(array $classes, bool $slint = false): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        $out = (new SlintCompiler($kits, 'shadcn', $this->dir))->compile($byName);
        if ($slint) {
            $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
            if (!is_file($compiler)) {
                self::markTestSkipped('needs the Slint toolchain');
            }
            file_put_contents("$this->dir/app.slint", $out->slint);
            $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '--embed-resources=embed-files', '-o', "$this->dir/app.h"];
            foreach ($out->libraries as $name => $path) {
                array_push($command, '-L', "$name=$path");
            }
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines) . "\n" . $out->slint);
        }
        return $out;
    }

    /** @param list<ClassInfo> $classes */
    private function fails(string $message, array $classes): void
    {
        try {
            $this->compile($classes);
            self::fail('expected an error');
        } catch (TemplateException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function loop(string $body, string $as = '$i => $d'): string
    {
        return "<VStack>\n@foreach (\$devices as $as)\n$body\n@endforeach\n</VStack>";
    }

    public function testForeachOverCollection(): void
    {
        $out = $this->compile([$this->collection(), $this->screen($this->loop('<Label text="{{ $d->name }}" />'))], slint: true);
        $binding = $out->windows[0]->pages[0]->bindings['screen:property:devices'];
        $p = $binding->slintName();
        self::assertSame('rows', $binding->type);
        self::assertSame('Demo\Device', $binding->row->item);
        self::assertStringContainsString("export struct RkRowDevice {\n    f0: string,\n}", $out->slint);
        self::assertStringContainsString("in property <[RkRowDevice]> $p;", $out->slint);
        self::assertStringContainsString("for d[i] in root.$p :", $out->slint);
        self::assertStringContainsString('\{d.f0}', $out->slint);
    }

    public function testFieldsInFirstUseOrderOnlyUsed(): void
    {
        $out = $this->compile([$this->collection(), $this->screen($this->loop("<VStack>\n<Progress value=\"{{ \$d->battery }}\" />\n<Label text=\"{{ \$d->name }}\" />\n</VStack>"))]);
        self::assertStringContainsString("export struct RkRowDevice {\n    f0: float,\n    f1: string,\n}", $out->slint);
        $fields = $out->windows[0]->pages[0]->bindings['screen:property:devices']->row->fields();
        self::assertSame(['battery', 'name'], array_map(fn($f) => $f->member, $fields));
    }

    public function testMemberMethod(): void
    {
        $out = $this->compile([$this->collection(), $this->screen($this->loop('<Label text="{{ $d->label() }}" />'))]);
        $field = $out->windows[0]->pages[0]->bindings['screen:property:devices']->row->fields()[0];
        self::assertSame(['label', 'method', 'string', 'f0'], [$field->member, $field->source, $field->type, $field->name]);
    }

    public function testMemberInConditionAndHandler(): void
    {
        $body = "<VStack>\n@if (\$d->online)\n<Label text=\"on\" />\n@endif\n<Button text=\"Pick\" onClick=\"pick(\$d->name)\" />\n</VStack>";
        $out = $this->compile([$this->collection(), $this->screen($this->loop($body), handlers: ['pick' => ['string']])]);
        self::assertStringContainsString('if d.f0 :', $out->slint);
        self::assertStringContainsString('\{d.f1}', $out->slint);
    }

    public function testCountCollection(): void
    {
        $out = $this->compile([$this->collection(), $this->screen('<Label text="{{ count($devices) }}" />')]);
        $p = $out->windows[0]->pages[0]->bindings['screen:property:devices']->slintName();
        self::assertStringContainsString("root.$p.length", $out->slint);
    }

    /** Review Focus 2: the same item class in two collections on two screens. */
    public function testTwoCollectionsShareOneStruct(): void
    {
        $out = $this->compile([
            $this->collection(),
            $this->collection('Demo\Headsets'),
            $this->screen($this->loop('<Label text="{{ $d->name }}" />')),
            $this->screen("<VStack>\n@foreach (\$headsets as \$h)\n<Label text=\"{{ \$h->label() }}\" />\n@endforeach\n</VStack>", ['headsets' => 'Demo\Headsets'], 'Other'),
        ], slint: true);
        self::assertSame(1, substr_count($out->slint, 'export struct RkRowDevice '));
        $rows = [];
        foreach ($out->windows[0]->pages as $page) {
            foreach ($page->bindings as $binding) {
                $rows[] = $binding->row;
            }
        }
        self::assertCount(2, $rows);
        self::assertSame($rows[0], $rows[1]);
        self::assertCount(2, $rows[0]->fields());
    }

    public function testStoreCollection(): void
    {
        $root = new ClassInfo('Demo\AppStore', 'AppStore', "$this->dir/AppStore.php", false, '', props: ['things' => 'Demo\Things'], isRootStore: true);
        $store = new ClassInfo('Demo\Things', 'Things', "$this->dir/Things.php", false, '', props: ['devices' => 'Demo\Devices'], isStore: true);
        $rk = "<VStack>\n@foreach (\$store->things->devices as \$d)\n<Label text=\"{{ \$d->name }}\" />\n@endforeach\n</VStack>";
        $out = $this->compile([$this->collection(), $root, $store, $this->screen($rk, [])]);
        $binding = $out->windows[0]->bindings['store:property:things.devices'];
        self::assertSame('rows', $binding->type);
        self::assertSame('store', $binding->owner);
    }

    /** Review Focus 5: ->member only works on a loop item. */
    public function testMemberOutsideLoop(): void
    {
        $this->fails('$devices is not an object; ->member works on @foreach items of a collection', [$this->collection(), $this->screen('<Label text="{{ $devices->name }}" />')]);
        $this->fails("'d' is not a property", [$this->collection(), $this->screen('<Label text="{{ $d->name }}" />')]);
        $this->fails('$devices is a collection; show its items with @foreach', [$this->collection(), $this->screen('<Label text="{{ $devices }}" />')]);
    }

    public function testErrors(): void
    {
        $c = $this->collection();
        $this->fails("\$d->nmae: Device has no public property or method 'nmae' (it has: name, image, battery, online, count, tags, nick, label(), rename())", [$c, $this->screen($this->loop('<Label text="{{ $d->nmae }}" />'))]);
        $this->fails("\$d->label(): methods shown in templates can't take arguments", [$c, $this->screen($this->loop('<Label text="{{ $d->label(1) }}" />'))]);
        $this->fails('$d->label is a method: write $d->label()', [$c, $this->screen($this->loop('<Label text="{{ $d->label }}" />'))]);
        $this->fails('$d->name is a property: write $d->name', [$c, $this->screen($this->loop('<Label text="{{ $d->name() }}" />'))]);
        $this->fails('$d->tags is an array; rows can show string, int, float and bool members', [$c, $this->screen($this->loop('<Label text="{{ $d->tags }}" />'))]);
        $this->fails("\$d->nick is ?string; rows can't show nullable members", [$c, $this->screen($this->loop('<Label text="{{ $d->nick }}" />'))]);
        $this->fails('$d->rename() returns void; rows can show string, int, float and bool members', [$c, $this->screen($this->loop('<Label text="{{ $d->rename() }}" />'))]);
        $this->fails('Devices::OF is empty; set public const OF = Item::class', [$this->collection(of: '', found: false), $this->screen($this->loop('<Label text="x" />'))]);
        $this->fails("Devices::OF names Demo\\Nope, which doesn't exist", [$this->collection(of: 'Demo\Nope', found: false), $this->screen($this->loop('<Label text="x" />'))]);
    }

    public function testStructNameClash(): void
    {
        $out = $this->compile([
            $this->collection(),
            $this->collection('Other\Devices', 'Other\Device'),
            $this->screen($this->loop('<Label text="{{ $d->name }}" />')),
            $this->screen("<VStack>\n@foreach (\$more as \$m)\n<Label text=\"{{ \$m->name }}\" />\n@endforeach\n</VStack>", ['more' => 'Other\Devices'], 'More'),
        ], slint: true);
        self::assertStringContainsString('export struct RkRowDevice {', $out->slint);
        self::assertStringContainsString('export struct RkRowDevice2 {', $out->slint);
    }

    private function rowsTemplate(): string
    {
        return $this->loop("<VStack>\n<Label text=\"{{ \$d->name }} {{ \$d->label() }}\" />\n<Image source=\"{{ \$d->image }}\" />\n<Progress value=\"{{ \$d->battery }}\" />\n</VStack>");
    }

    public function testBinderReadsRows(): void
    {
        $out = $this->compile([$this->collection(), $this->screen($this->rowsTemplate())]);
        $php = BinderEmitter::emit($out->windows);
        self::assertStringContainsString('self::rowsRkRowDevice($s->devices)', $php);
        self::assertStringContainsString('private static function rowsRkRowDevice(\RageKit\Collection $c): \RageKit\Slint\RowsValue', $php);
        self::assertStringContainsString('if ($item instanceof \Demo\Device) {', $php);
        self::assertStringContainsString('(string) $item->name, (string) $item->label(), new \RageKit\Slint\ImageValue((string) $item->image), (float) $item->battery', $php);
        $file = "$this->dir/binder.php";
        file_put_contents($file, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
    }

    public function testBinderReadsImageLists(): void
    {
        $screen = $this->screen("<VStack>\n@foreach (\$photos as \$p)\n<Image source=\"{{ \$p }}\" />\n@endforeach\n</VStack>", ['photos' => 'list']);
        $out = $this->compile([$screen]);
        $images = $out->windows[0]->pages[0]->bindings['screen:property:photos:images']->id;
        self::assertStringContainsString("$images => self::images(\$s->photos),", BinderEmitter::emit($out->windows));
    }

    /** Review Focus 1: an item of a subclass of OF is a row like any other; other objects are skipped. */
    public function testSubclassItemsShow(): void
    {
        $out = $this->compile([$this->collection(), $this->screen($this->rowsTemplate())]);
        file_put_contents("$this->dir/binder.php", BinderEmitter::emit($out->windows));
        $root = (string) realpath(self::ROOT);
        file_put_contents("$this->dir/run.php", <<<PHP
            <?php
            namespace {
                require '$root/vendor/autoload.php';
            }
            namespace Demo {
                class Device { public string \$name = 'n'; public string \$image = 'a.png'; public float \$battery = 0.5; public function label(): string { return 'L'; } }
                final class Headset extends Device {}
                final class Devices extends \\RageKit\\Collection { public const OF = Device::class; }
            }
            namespace {
                require '$this->dir/binder.php';
                \$c = new \\Demo\\Devices([new \\Demo\\Device(), new \\Demo\\Headset()]);
                \$m = new \\ReflectionMethod(\\RageKit\\Generated\\Screens::class, 'rowsRkRowDevice');
                \$rows = \$m->invoke(null, \$c)->rows;
                echo count(\$rows), ' ', \$rows[1][0], ' ', \$rows[1][1], ' ', \$rows[1][2]->source, ' ', \$rows[1][3];
            }
            PHP);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$this->dir/run.php") . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        self::assertSame('2 n L a.png 0.5', implode("\n", $lines));
    }

    public function testScreensJsonRow(): void
    {
        $out = $this->compile([$this->collection(), $this->screen($this->rowsTemplate())]);
        $json = json_decode(ScreensJson::emit($out->windows), true);
        $value = $json['windows'][0]['pages'][0]['values'][0];
        self::assertSame('rows', $value['type']);
        self::assertSame([
            'item' => 'Demo\Device',
            'fields' => [
                ['member' => 'name', 'source' => 'property', 'type' => 'string', 'name' => 'f0'],
                ['member' => 'label', 'source' => 'method', 'type' => 'string', 'name' => 'f1'],
                ['member' => 'image', 'source' => 'property', 'type' => 'image', 'name' => 'f2'],
                ['member' => 'battery', 'source' => 'property', 'type' => 'float', 'name' => 'f3'],
            ],
        ], $value['row']);
    }
}
