<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Tests\Support\InstalledToolchain;

/** Screens sharing a layout compile into one window component, one page each (the Navigator). */
final class NavigatorCompileTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const CONTEXT = ['title' => ['return' => 'string', 'params' => [], 'required' => 0]];
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-navcompile-' . uniqid();
        mkdir($this->dir);
    }

    private function screen(string $short, string $rk, string $layout = 'Demo\AppWindow'): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk",
            props: ['count' => 'int', 'confirm' => 'bool'], computed: ['title' => 'string'],
            handlers: ['add' => []], requiredParams: ['add' => 0],
            window: $layout, interfaces: $layout === '' ? [] : ['Demo\Ctx']);
    }

    private function layout(string $rk = '<VStack padded="false"><Label text="{{ $context->title() }}" /><Content /></VStack>'): ClassInfo
    {
        file_put_contents("$this->dir/AppWindow.rk", $rk);
        return new ClassInfo('Demo\AppWindow', 'AppWindow', "$this->dir/AppWindow.php", false, "$this->dir/AppWindow.rk",
            props: ['maximized' => 'bool'], handlers: ['back' => []], requiredParams: ['back' => 0],
            isWindow: true, context: 'Demo\Ctx', contextMembers: self::CONTEXT);
    }

    /** @param list<ClassInfo> $classes */
    private function compile(array $classes, string $kit = 'shadcn', bool $slint = false): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        $out = (new SlintCompiler($kits, $kit))->compile($byName);
        if ($slint) {
            $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
            if (!is_file($compiler)) {
                self::markTestSkipped('needs the Slint toolchain');
            }
            file_put_contents("$this->dir/app.slint", $out->slint);
            $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '-o', "$this->dir/app.h"];
            foreach ($out->libraries as $name => $path) {
                array_push($command, '-L', "$name=$path");
            }
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines));
        }
        return $out;
    }

    /** @return list<ClassInfo> */
    private function app(): array
    {
        return [
            $this->layout(),
            $this->screen('Albums', '<Button text="Add" onClick="add" />'),
            $this->screen('Photos', '<Label text="{{ $count }}" />'),
            $this->screen('About', '<Label text="About" />', ''),
            $this->screen('Help', '<Label text="Help {{ $count }}" />', ''),
        ];
    }

    public function testScreensWithOneLayoutShareAWindow(): void
    {
        $out = $this->compile($this->app());
        self::assertSame(1, substr_count($out->slint, 'export component rk-screens-AppWindow inherits'));
        self::assertStringNotContainsString('export component Albums', $out->slint);
        self::assertStringNotContainsString('export component Photos', $out->slint);
        self::assertStringContainsString('in property <int> rk-page;', $out->slint);
        $window = $this->window($out, 'rk-screens-AppWindow');
        self::assertSame(['Albums', 'Photos'], array_map(fn($p) => $p->class->short, $window->pages));
        self::assertSame([0, 1], array_map(fn($p) => $p->page, $window->pages));
        self::assertStringContainsString('if root.rk-page == 0 : VerticalLayout {', $out->slint);
        self::assertStringContainsString('if root.rk-page == 1 : VerticalLayout {', $out->slint);
    }

    public function testScreensWithoutLayoutShareOneWindow(): void
    {
        $out = $this->compile($this->app());
        self::assertSame(1, substr_count($out->slint, 'export component rk-screens inherits'));
        self::assertSame(['About', 'Help'], array_map(fn($p) => $p->class->short, $this->window($out, 'rk-screens')->pages));
    }

    /**
     * Several pages sit in a layout (as <Tabs> pages do): an `if` straight under the window doesn't
     * pass its minimum size on, so the window could shrink below the page and clip it.
     */
    public function testSeveralPagesSitInALayout(): void
    {
        $out = $this->compile($this->app());
        $plain = substr($out->slint, strpos($out->slint, 'export component rk-screens inherits'));
        self::assertMatchesRegularExpression('/VerticalLayout \{\s+padding: 0px;\s+spacing: 0px;\s+alignment: stretch;\s+if root\.rk-page == 0 : VerticalLayout \{/', $plain);
    }

    /** An app that never navigates keeps today's window: no page condition around its only screen. */
    public function testOnePageNeedsNoCondition(): void
    {
        $out = $this->compile([$this->screen('Solo', '<Label text="Hi" />', '')]);
        self::assertStringNotContainsString('rk-page ==', $out->slint);
        self::assertStringContainsString('in property <int> rk-page;', $out->slint);
    }

    public function testIdsAreUniquePerWindow(): void
    {
        $out = $this->compile($this->app());
        foreach ($out->windows as $window) {
            $bindings = $window->bindings;
            $actions = $window->actions;
            foreach ($window->pages as $page) {
                $bindings = [...array_values($bindings), ...array_values($page->bindings)];
                $actions = [...$actions, ...$page->actions];
            }
            $ids = array_map(fn($b) => $b->id, array_values($bindings));
            self::assertSame(count($ids), count(array_unique($ids)), "binding ids of {$window->name}");
            $ids = array_map(fn($a) => $a->id, $actions);
            self::assertSame(count($ids), count(array_unique($ids)), "action ids of {$window->name}");
        }
    }

    public function testContextBindingIsSharedOnce(): void
    {
        $window = $this->window($this->compile($this->app()), 'rk-screens-AppWindow');
        self::assertSame('context', $window->bindings['context:method:title']->owner);
        foreach ($window->pages as $page) {
            self::assertArrayNotHasKey('screen:method:title', $page->bindings);
        }
    }

    public function testDialogIsGuardedByItsPage(): void
    {
        $out = $this->compile([
            $this->layout(),
            $this->screen('Albums', '<Label text="A" />'),
            $this->screen('Photos', '<VStack><Dialog open="{{ $confirm }}" title="Sure?"><Label text="x" /></Dialog></VStack>'),
        ]);
        $confirm = $this->window($out, 'rk-screens-AppWindow')->pages[1]->bindings['screen:property:confirm'];
        self::assertStringContainsString('if root.rk-page == 1 && root.' . $confirm->slintName() . ' : ShadDialog {', $out->slint);
    }

    public function testToastsOncePerWindow(): void
    {
        $out = $this->compile($this->app());
        $window = $this->window($out, 'rk-screens-AppWindow');
        self::assertSame('window', $window->bindings['window:method:toastTexts']->owner);
        self::assertSame(2, substr_count($out->slint, 'Timer {'), 'one toast timer per window component');
    }

    /** Alt+Left goes back: key presses no focused element takes reach the window's FocusScope. */
    public function testAltLeftSendsBack(): void
    {
        $out = $this->compile($this->app());
        self::assertSame(2, substr_count($out->slint, 'if (event.modifiers.alt && event.text == Key.LeftArrow) {'));
        self::assertStringContainsString("root.action(-3, \"\");\n                return accept;", $out->slint);
    }

    public function testCompilesWithBothKits(): void
    {
        foreach (['shadcn', 'material'] as $kit) {
            $this->compile($this->app(), $kit, slint: true);
        }
    }

    private function window(CompiledViews $out, string $name): \RageKit\Cli\Slint\WindowModel
    {
        foreach ($out->windows as $window) {
            if ($window->name === $name) {
                return $window;
            }
        }
        self::fail("no window $name");
    }
}
