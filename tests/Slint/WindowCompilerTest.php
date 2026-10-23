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

final class WindowCompilerTest extends TestCase
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
        $this->dir = sys_get_temp_dir() . '/rk-window-' . uniqid();
        mkdir($this->dir);
    }

    private function frame(string $rk, string $context = 'Fix\TitleBarContext', array $props = ['query' => 'string', 'maximized' => 'bool'], bool $constructible = true): ClassInfo
    {
        file_put_contents("$this->dir/AppWindow.rk", $rk);
        return new ClassInfo('Fix\AppWindow', 'AppWindow', "$this->dir/AppWindow.php", false, "$this->dir/AppWindow.rk",
            props: $props,
            computed: ['minimize' => 'void', 'toggleMaximize' => 'void', 'close' => 'void'],
            handlers: ['minimize' => [], 'toggleMaximize' => [], 'close' => [], 'search' => []],
            isWindow: true, context: $context, contextMembers: $context === '' ? [] : self::CONTEXT, constructible: $constructible);
    }

    private function screen(string $rk, string $window = 'Fix\AppWindow', array $interfaces = ['Fix\TitleBarContext'], string $short = 'HomeScreen'): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Fix\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk",
            props: ['count' => 'int'], computed: ['title' => 'string'], handlers: ['refresh' => ['string']],
            requiredParams: ['refresh' => 0], window: $window, interfaces: $interfaces);
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

    public function testWindowFramesScreen(): void
    {
        $out = $this->compile([$this->frame(self::BAR), $this->screen('<Label text="Hi" />')]);
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
        // {context.title}: the screen on top's title(), a window-level binding.
        $title = $out->screens[0]->window->bindings['context:method:title'];
        self::assertSame('context', $title->owner);
        self::assertSame($this->frameClass()->name, $out->screens[0]->frame?->name);
    }

    private function frameClass(): ClassInfo
    {
        return $this->frame(self::BAR);
    }

    public function testWindowOwnPropertyAndHandler(): void
    {
        $out = $this->compile([
            $this->frame('<VStack><Entry value="{query}" /><Button text="x" onClick="close" /><Content /></VStack>'),
            $this->screen('<Label text="{count}" />'),
        ]);
        $model = $out->screens[0];
        $window = $model->window;
        $query = $window->bindings['window:property:query'];
        self::assertTrue($query->twoWay);
        self::assertSame('window', $query->owner);
        self::assertSame('screen', $model->bindings['screen:property:count']->owner);
        $close = array_values(array_filter($window->actions, fn($a) => $a->target === 'close'))[0];
        self::assertSame('window', $close->owner);
        $set = array_values(array_filter($window->actions, fn($a) => $a->kind === 'set'))[0];
        self::assertSame('window', $set->owner);
    }

    public function testContextHandler(): void
    {
        $out = $this->compile([
            $this->frame('<VStack><Button text="r" onClick="context.refresh(\'bar\')" /><Content /></VStack>'),
            $this->screen('<Label text="x" />'),
        ]);
        $action = $out->screens[0]->window->actions[0];
        self::assertSame('context', $action->owner);
        self::assertSame('refresh', $action->target);
        self::assertSame(['string'], $action->params);
    }

    public function testRagebladeContextCalls(): void
    {
        $out = $this->compile([
            $this->frame('<VStack><Label text="{{ $context->title() }}!" /><Button text="r" onClick="$context->refresh(\'bar\')" /><Content /></VStack>'),
            $this->screen('<Label text="x" />'),
        ]);
        $window = $out->screens[0]->window;
        self::assertSame('context', $window->bindings['context:method:title']->owner);
        $action = $window->actions[0];
        self::assertSame(['context', 'refresh', [['literal', 'bar']]], [$action->owner, $action->target, $action->args]);
    }

    public function testPlainWindowUnchanged(): void
    {
        file_put_contents("$this->dir/Plain.rk", '<Label text="{count}" />');
        $plain = new ClassInfo('Fix\Plain', 'Plain', "$this->dir/Plain.php", true, "$this->dir/Plain.rk", props: ['count' => 'int']);
        $out = $this->compile([$plain]);
        self::assertStringNotContainsString('no-frame', $out->slint);
        self::assertStringNotContainsString('rk-window', $out->slint);
        self::assertNull($out->screens[0]->frame);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> window rk, screen rk, message */
    public static function errors(): iterable
    {
        yield 'no content' => ['<VStack><Label text="x" /></VStack>', '<Label text="y" />', 'needs exactly one <Content />'];
        yield 'two contents' => ['<VStack><Content /><Content /></VStack>', '<Label text="y" />', 'needs exactly one <Content />'];
        yield 'content in screen' => [self::BAR, '<VStack><Content /></VStack>', "<Content /> is only allowed in a Window's template"];
        yield 'drag in screen' => [self::BAR, '<WindowDrag><Label text="y" /></WindowDrag>', "<WindowDrag> is only allowed in a Window's template"];
        yield 'controls in screen' => [self::BAR, '<WindowControls />', "<WindowControls> is only allowed in a Window's template"];
        yield 'undeclared context member' => ['<VStack><Label text="{context.nope}" /><Content /></VStack>', '<Label text="y" />', "'nope' is not declared by Fix\\TitleBarContext"];
        yield 'unshowable context member' => ['<VStack><Label text="{context.avatar}" /><Content /></VStack>', '<Label text="y" />', "'avatar()' returns object"];
        yield 'two-way context' => ['<VStack><Entry value="{context.title}" /><Content /></VStack>', '<Label text="y" />', "two-way inputs in a window's template must bind the window's own properties"];
        yield 'content with when' => ['<VStack><Content when="maximized" /></VStack>', '<Label text="y" />', "<Content /> can't have each= or when="];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $frame, string $screen, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile([$this->frame($frame), $this->screen($screen)]);
    }

    public function testScreenMustImplementContext(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('HomeScreen uses AppWindow, which needs Fix\TitleBarContext; implement it');
        $this->compile([$this->frame(self::BAR), $this->screen('<Label text="y" />', interfaces: [])]);
    }

    public function testContextNeedsContextDeclared(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('AppWindow has no context()');
        $this->compile([$this->frame(self::BAR, context: ''), $this->screen('<Label text="y" />')]);
    }

    public function testWindowWithoutTemplateIsAnError(): void
    {
        $bare = new ClassInfo('Fix\AppWindow', 'AppWindow', "$this->dir/AppWindow.php", false, '', isWindow: true);
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('AppWindow extends Window: give it a template, or use Window itself for the OS title bar');
        $this->compile([$bare, $this->screen('<Label text="y" />')]);
    }

    public function testScreenNamingANonWindowClassIsAnError(): void
    {
        file_put_contents("$this->dir/Card.rk", '<Label text="c" />');
        $child = new ClassInfo('Fix\Card', 'Card', "$this->dir/Card.php", false, "$this->dir/Card.rk");
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("HomeScreen::window() names Fix\\Card, which isn't a Window of this app");
        $this->compile([$child, $this->screen('<Label text="y" />', window: 'Fix\Card')]);
    }

    /** Screens using Window itself (the default) share the plain rk-screens component. */
    public function testPlainWindowScreensShareRkScreens(): void
    {
        file_put_contents("$this->dir/A.rk", '<Label text="a" />');
        file_put_contents("$this->dir/B.rk", '<Label text="b" />');
        $out = $this->compile([
            new ClassInfo('Fix\A', 'A', "$this->dir/A.php", true, "$this->dir/A.rk"),
            new ClassInfo('Fix\B', 'B', "$this->dir/B.php", true, "$this->dir/B.rk"),
        ]);
        self::assertCount(1, $out->windows);
        self::assertSame('rk-screens', $out->windows[0]->name);
        self::assertNull($out->windows[0]->frame);
        $php = BinderEmitter::emit($out->windows);
        self::assertStringContainsString("if (\$screen instanceof \\Fix\\B) {\n            return 'rk-screens';", $php);
    }

    /** Toasts are the window's: owner window, read from Window::toastTexts(). */
    public function testToastsAreAWindowBinding(): void
    {
        $out = $this->compile([$this->frame(self::BAR), $this->screen('<Label text="y" />')]);
        $toasts = $out->windows[0]->bindings['window:method:toastTexts'];
        self::assertSame('window', $toasts->owner);
        self::assertStringContainsString("{$toasts->id} => self::strings(\$w->toastTexts()),", BinderEmitter::emit($out->windows));
    }

    public function testWindowMustBeConstructible(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('AppWindow must be constructible without arguments');
        $this->compile([$this->frame(self::BAR, constructible: false), $this->screen('<Label text="y" />')]);
    }

    public function testUnknownWindowClass(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("HomeScreen::window() names Fix\\Missing, which isn't a Window of this app");
        $this->compile([$this->screen('<Label text="y" />', window: 'Fix\Missing')]);
    }

    public function testEmittersForFramedScreen(): void
    {
        $out = $this->compile([
            $this->frame('<VStack><WindowDrag><Label text="{context.title}" /><Entry value="{query}" /><Button text="s" onClick="search" /></WindowDrag><Content /></VStack>'),
            $this->screen('<Label text="{count}" />'),
        ]);
        $php = BinderEmitter::emit($out->windows);
        self::assertStringContainsString('public static function newWindow(Screen $screen): Window', $php);
        self::assertStringContainsString('return new \Fix\AppWindow();', $php);
        self::assertStringContainsString('if ($w instanceof \Fix\AppWindow) {', $php);
        self::assertStringContainsString('$w->query', $php);
        self::assertStringContainsString('$s->title()', $php);
        self::assertStringContainsString('$w->search();', $php);
        self::assertStringContainsString('$s->count', $php);
        $lint = sys_get_temp_dir() . '/rk-binder-' . uniqid() . '.php';
        file_put_contents($lint, $php);
        exec('php -l ' . escapeshellarg($lint) . ' 2>&1', $lines, $code);
        unlink($lint);
        self::assertSame(0, $code, implode("\n", $lines));

        $glue = GlueEmitter::emit($out->windows);
        self::assertStringContainsString('handle_->on_rk_window(', $glue);
        self::assertStringContainsString('rk::window_command(window, handle_->window(), std::string_view(command));', $glue);
        self::assertStringContainsString('void set_maximized(bool value) override { handle_->set_rk_maximized(value); }', $glue);
        self::assertStringContainsString('bool frameless() const override { return true; }', $glue);
        self::assertStringContainsString('bool rk::any_frameless()' . "\n{\n    return true;\n}", $glue);

        $json = json_decode(ScreensJson::emit($out->windows), true);
        $window = $json['windows'][0];
        self::assertSame('Fix\AppWindow', $window['window']);
        self::assertTrue($window['frameless']);
        self::assertContains('window', array_column($window['values'], 'owner'));
        self::assertContains('context', array_column($window['values'], 'owner'));
        self::assertContains('screen', array_column($window['pages'][0]['values'], 'owner'));
        self::assertSame('window', $window['actions'][0]['owner']);
    }

    public function testGlueWithoutFramesIsNotFrameless(): void
    {
        file_put_contents("$this->dir/Plain.rk", '<Label text="x" />');
        $out = $this->compile([new ClassInfo('Fix\Plain', 'Plain', "$this->dir/Plain.php", true, "$this->dir/Plain.rk")]);
        $glue = GlueEmitter::emit($out->windows);
        self::assertStringContainsString('bool rk::any_frameless()' . "\n{\n    return false;\n}", $glue);
        self::assertStringNotContainsString('on_rk_window', $glue);
        self::assertStringContainsString('return new \RageKit\Window();', BinderEmitter::emit($out->windows));
    }
}
