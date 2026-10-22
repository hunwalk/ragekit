<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\GlueEmitter;
use RageKit\Cli\Slint\ScreensJson;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\TemplateException;

final class LayoutCompilerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const CONTEXT = [
        'title' => ['return' => 'string', 'params' => [], 'required' => 0],
        'refresh' => ['return' => 'void', 'params' => ['string'], 'required' => 0],
        'avatar' => ['return' => 'object', 'params' => [], 'required' => 0],
    ];
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-layout-' . uniqid();
        mkdir($this->dir);
    }

    private function layout(string $rk, string $context = 'Fix\TitleBarContext', array $props = ['query' => 'string', 'maximized' => 'bool'], bool $constructible = true): ClassInfo
    {
        file_put_contents("$this->dir/AppLayout.rk", $rk);
        return new ClassInfo('Fix\AppLayout', 'AppLayout', "$this->dir/AppLayout.php", false, "$this->dir/AppLayout.rk",
            props: $props,
            computed: ['minimize' => 'void', 'toggleMaximize' => 'void', 'close' => 'void'],
            handlers: ['minimize' => [], 'toggleMaximize' => [], 'close' => [], 'search' => []],
            isLayout: true, context: $context, contextMembers: $context === '' ? [] : self::CONTEXT, constructible: $constructible);
    }

    private function screen(string $rk, string $layout = 'Fix\AppLayout', array $interfaces = ['Fix\TitleBarContext'], string $short = 'HomeScreen'): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Fix\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk",
            props: ['count' => 'int'], computed: ['title' => 'string'], handlers: ['refresh' => ['string']],
            requiredParams: ['refresh' => 0], layout: $layout, interfaces: $interfaces);
    }

    /** @param list<ClassInfo> $classes */
    private function compile(array $classes): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/tests/fixtures/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        return (new SlintCompiler($kits, 'shadcn'))->compile($byName);
    }

    private const BAR = '<VStack padded="false"><WindowDrag><Label text="{context.title}" /><WindowControls /></WindowDrag><Content /></VStack>';

    public function testLayoutWrapsScreen(): void
    {
        $out = $this->compile([$this->layout(self::BAR), $this->screen('<Label text="Hi" />')]);
        self::assertStringContainsString('no-frame: true;', $out->slint);
        self::assertStringContainsString('callback rk-window(string);', $out->slint);
        self::assertStringContainsString('in property <bool> rk-maximized;', $out->slint);
        self::assertStringContainsString('root.rk-window("move-start");', $out->slint);
        self::assertStringContainsString('root.rk-window("move");', $out->slint);
        // Double-clicks are found natively from presses (the OS's move loop eats the releases).
        self::assertStringNotContainsString('double-clicked', $out->slint);
        self::assertStringContainsString('changed width => { root.rk-window("resized"); }', $out->slint);
        self::assertStringContainsString('root.rk-window("resize-bottom-right");', $out->slint);
        self::assertStringContainsString('root.rk-window("resize-move");', $out->slint);
        self::assertStringContainsString('ShadWindowControls {', $out->slint);
        self::assertStringContainsString('maximized: root.rk-maximized;', $out->slint);
        self::assertStringContainsString('close => { root.rk-window("close"); }', $out->slint);
        self::assertStringContainsString('"Hi"', $out->slint);
        $title = $out->screens[0]->bindings['screen:method:title'];
        self::assertSame('screen', $title->owner);
        self::assertSame($this->layoutClass()->name, $out->screens[0]->layout?->name);
    }

    private function layoutClass(): ClassInfo
    {
        return $this->layout(self::BAR);
    }

    public function testLayoutOwnPropertyAndHandler(): void
    {
        $out = $this->compile([
            $this->layout('<VStack><Entry value="{query}" /><Button text="x" onClick="close" /><Content /></VStack>'),
            $this->screen('<Label text="{count}" />'),
        ]);
        $model = $out->screens[0];
        $query = $model->bindings['layout:property:query'];
        self::assertTrue($query->twoWay);
        self::assertSame('layout', $query->owner);
        self::assertSame('screen', $model->bindings['screen:property:count']->owner);
        $close = array_values(array_filter($model->actions, fn($a) => $a->target === 'close'))[0];
        self::assertSame('layout', $close->owner);
        $set = array_values(array_filter($model->actions, fn($a) => $a->kind === 'set'))[0];
        self::assertSame('layout', $set->owner);
    }

    public function testContextHandler(): void
    {
        $out = $this->compile([
            $this->layout('<VStack><Button text="r" onClick="context.refresh(\'bar\')" /><Content /></VStack>'),
            $this->screen('<Label text="x" />'),
        ]);
        $action = $out->screens[0]->actions[0];
        self::assertSame('screen', $action->owner);
        self::assertSame('refresh', $action->target);
        self::assertSame(['string'], $action->params);
    }

    public function testRagebladeContextCalls(): void
    {
        $out = $this->compile([
            $this->layout('<VStack><Label text="{{ $context->title() }}!" /><Button text="r" onClick="$context->refresh(\'bar\')" /><Content /></VStack>'),
            $this->screen('<Label text="x" />'),
        ]);
        $model = $out->screens[0];
        self::assertSame('screen', $model->bindings['screen:method:title']->owner);
        $action = $model->actions[0];
        self::assertSame(['screen', 'refresh', [['literal', 'bar']]], [$action->owner, $action->target, $action->args]);
    }

    public function testScreenWithoutLayoutUnchanged(): void
    {
        file_put_contents("$this->dir/Plain.rk", '<Label text="{count}" />');
        $plain = new ClassInfo('Fix\Plain', 'Plain', "$this->dir/Plain.php", true, "$this->dir/Plain.rk", props: ['count' => 'int']);
        $out = $this->compile([$plain]);
        self::assertStringNotContainsString('no-frame', $out->slint);
        self::assertStringNotContainsString('rk-window', $out->slint);
        self::assertNull($out->screens[0]->layout);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> layout rk, screen rk, message */
    public static function errors(): iterable
    {
        yield 'no content' => ['<VStack><Label text="x" /></VStack>', '<Label text="y" />', 'needs exactly one <Content />'];
        yield 'two contents' => ['<VStack><Content /><Content /></VStack>', '<Label text="y" />', 'needs exactly one <Content />'];
        yield 'content in screen' => [self::BAR, '<VStack><Content /></VStack>', "<Content /> is only allowed in a Layout's template"];
        yield 'drag in screen' => [self::BAR, '<WindowDrag><Label text="y" /></WindowDrag>', "<WindowDrag> is only allowed in a Layout's template"];
        yield 'controls in screen' => [self::BAR, '<WindowControls />', "<WindowControls> is only allowed in a Layout's template"];
        yield 'undeclared context member' => ['<VStack><Label text="{context.nope}" /><Content /></VStack>', '<Label text="y" />', "'nope' is not declared by Fix\\TitleBarContext"];
        yield 'unshowable context member' => ['<VStack><Label text="{context.avatar}" /><Content /></VStack>', '<Label text="y" />', "'avatar()' returns object"];
        yield 'two-way context' => ['<VStack><Entry value="{context.title}" /><Content /></VStack>', '<Label text="y" />', "two-way inputs in a layout must bind the layout's own properties"];
        yield 'content with when' => ['<VStack><Content when="maximized" /></VStack>', '<Label text="y" />', "<Content /> can't have each= or when="];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $layout, string $screen, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile([$this->layout($layout), $this->screen($screen)]);
    }

    public function testScreenMustImplementContext(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('HomeScreen uses AppLayout, which needs Fix\TitleBarContext; implement it');
        $this->compile([$this->layout(self::BAR), $this->screen('<Label text="y" />', interfaces: [])]);
    }

    public function testContextNeedsContextDeclared(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('AppLayout has no context()');
        $this->compile([$this->layout(self::BAR, context: ''), $this->screen('<Label text="y" />')]);
    }

    public function testLayoutMustBeConstructible(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('AppLayout must be constructible without arguments');
        $this->compile([$this->layout(self::BAR, constructible: false), $this->screen('<Label text="y" />')]);
    }

    public function testUnknownLayoutClass(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("HomeScreen::layout() names Fix\\Missing, which isn't a Layout of this app");
        $this->compile([$this->screen('<Label text="y" />', layout: 'Fix\Missing')]);
    }

    public function testEmittersForLayoutScreen(): void
    {
        $out = $this->compile([
            $this->layout('<VStack><WindowDrag><Label text="{context.title}" /><Entry value="{query}" /><Button text="s" onClick="search" /></WindowDrag><Content /></VStack>'),
            $this->screen('<Label text="{count}" />'),
        ]);
        $php = BinderEmitter::emit($out->screens);
        self::assertStringContainsString('public static function layout(Screen $screen): ?Layout', $php);
        self::assertStringContainsString('return new \Fix\AppLayout();', $php);
        self::assertStringContainsString('$l = $screen->layoutObject();', $php);
        self::assertStringContainsString('if ($l instanceof \Fix\AppLayout) {', $php);
        self::assertStringContainsString('$l->query', $php);
        self::assertStringContainsString('$s->title()', $php);
        self::assertStringContainsString('$l->search();', $php);
        self::assertStringContainsString('$s->count', $php);
        $lint = sys_get_temp_dir() . '/rk-binder-' . uniqid() . '.php';
        file_put_contents($lint, $php);
        exec('php -l ' . escapeshellarg($lint) . ' 2>&1', $lines, $code);
        unlink($lint);
        self::assertSame(0, $code, implode("\n", $lines));

        $glue = GlueEmitter::emit($out->screens);
        self::assertStringContainsString('handle_->on_rk_window(', $glue);
        self::assertStringContainsString('rk::window_command(window, handle_->window(), std::string_view(command));', $glue);
        self::assertStringContainsString('void set_maximized(bool value) override { handle_->set_rk_maximized(value); }', $glue);
        self::assertStringContainsString('bool frameless() const override { return true; }', $glue);
        self::assertStringContainsString('bool rk::any_frameless()' . "\n{\n    return true;\n}", $glue);

        $json = json_decode(ScreensJson::emit($out->screens), true);
        self::assertSame('Fix\AppLayout', $json['screens'][0]['layout']);
        self::assertTrue($json['screens'][0]['frameless']);
        self::assertContains('layout', array_column($json['screens'][0]['values'], 'owner'));
        self::assertContains('screen', array_column($json['screens'][0]['values'], 'owner'));
        self::assertSame('layout', $json['screens'][0]['actions'][0]['owner']);
    }

    public function testGlueWithoutLayoutsIsNotFrameless(): void
    {
        file_put_contents("$this->dir/Plain.rk", '<Label text="x" />');
        $out = $this->compile([new ClassInfo('Fix\Plain', 'Plain', "$this->dir/Plain.php", true, "$this->dir/Plain.rk")]);
        $glue = GlueEmitter::emit($out->screens);
        self::assertStringContainsString('bool rk::any_frameless()' . "\n{\n    return false;\n}", $glue);
        self::assertStringNotContainsString('on_rk_window', $glue);
        self::assertStringContainsString('return null;', BinderEmitter::emit($out->screens));
    }
}
