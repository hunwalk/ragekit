<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime;

use PHPUnit\Framework\TestCase;
use RageKit\App;

require_once __DIR__ . '/FakeHost.php';
require_once __DIR__ . '/FakeScreens.php';

final class SlintRuntimeTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        FakeHost::reset();
        if (!function_exists('ragekit_backend')) {
            eval('function ragekit_backend(): \RageKit\Backend { return new \RageKit\Slint\SlintBackend(); }');
        }
        $this->app = new App();
    }

    private function event(int $window, int $action, string $arg = ''): void
    {
        FakeHost::$calls = [];
        (FakeHost::$dispatcher)($window, $action, $arg);
    }

    public function testLayoutIsCreatedPerWindow(): void
    {
        $first = new FramedScreen();
        $second = new FramedScreen();
        $this->app->open($first, title: 'One');
        $this->app->open($second, title: 'Two');

        $one = $first->layoutObject();
        $two = $second->layoutObject();
        self::assertInstanceOf(FrameLayout::class, $one);
        self::assertInstanceOf(FrameLayout::class, $two);
        self::assertNotSame($one, $two);
        self::assertSame(1, $one->windowId());
        self::assertSame(2, $two->windowId());
        self::assertSame('Framed', $one->contextTitle());
        self::assertContains('string 1 1 Framed', FakeHost::$calls);
    }

    public function testLayoutValuesArePushed(): void
    {
        $this->app->open(new FramedScreen(), title: 'One');
        $this->event(1, 3);
        self::assertSame(['string 1 2 searched'], FakeHost::$calls);
    }

    public function testMaximizedChangeFromOs(): void
    {
        $screen = new FramedScreen();
        $this->app->open($screen, title: 'One');
        $this->event(1, -1, '1');
        self::assertTrue($screen->layoutObject()?->maximized);
        self::assertSame(['bool 1 3 true'], FakeHost::$calls);
        $this->event(1, -1, '0');
        self::assertFalse($screen->layoutObject()?->maximized);
    }

    public function testLayoutCloseButtonClosesWindow(): void
    {
        $this->app->open(new FramedScreen(), title: 'One');
        $this->event(1, 1);
        self::assertSame(['close 1', 'quit'], FakeHost::$calls);
    }

    public function testOpenPushesInitialValues(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter', width: 360, height: 400);

        self::assertSame([
            'open CounterScreen "Counter" 360x400',
            'int 1 1 0',
            'string 1 2 ',
            'list 1 3',
            'list end',
        ], FakeHost::$calls);
    }

    public function testActionPushesOnlyChangedValues(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 1);

        self::assertSame(['int 1 1 1', 'list 1 3', '  item +1 → 1', 'list end'], FakeHost::$calls);
    }

    public function testReloadForgetsSentValues(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        FakeHost::$calls = [];
        $this->app->backend()->resync();
        self::assertSame(['int 1 1 0', 'string 1 2 ', 'list 1 3', 'list end'], FakeHost::$calls);
    }

    public function testModuleEventsResyncWindows(): void
    {
        $screen = new CounterScreen();
        $this->app->open($screen, title: 'Counter');
        \RageKit\Events::on('audio', 1, 'finished', function (string $data) use ($screen): void {
            $screen->count = 5;
        });
        FakeHost::$calls = [];
        (FakeHost::$eventDispatcher)('audio', 1, 'finished', '');
        self::assertSame(['int 1 1 5'], FakeHost::$calls);
        \RageKit\Events::off('audio', 1);
    }

    public function testAppResolve(): void
    {
        self::assertSame('/app/assets/a.wav', App::resolve('assets/a.wav'));
        self::assertSame('/abs/x.wav', App::resolve('/abs/x.wav'));
        self::assertSame('C:/x.wav', App::resolve('C:/x.wav'));
    }

    public function testTwoWayInputIsNotPushedBack(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 2, 'Ann');

        self::assertSame([], FakeHost::$calls);
    }

    public function testHandlerOpeningAWindowIsSynced(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 3);

        self::assertSame(['open AboutScreen "About" 300x100'], FakeHost::$calls);
    }

    public function testClosingLastWindowQuits(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 3);
        $this->event(2, 0);
        self::assertSame(['close 2'], FakeHost::$calls);
        $this->event(1, 0);
        self::assertSame(['close 1', 'quit'], FakeHost::$calls);
    }
}
