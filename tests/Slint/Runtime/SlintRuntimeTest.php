<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime;

use PHPUnit\Framework\TestCase;
use RageKit\App;

require_once __DIR__ . '/FakeHost.php';
require_once __DIR__ . '/FakeScreens.php';

final class SlintRuntimeTest extends TestCase
{
    private RuntimeApp $app;

    protected function setUp(): void
    {
        FakeHost::reset();
        if (!function_exists('ragekit_backend')) {
            eval('function ragekit_backend(): \RageKit\Backend { return new \RageKit\Slint\SlintBackend(); }');
        }
        $this->app = new RuntimeApp();
        new RuntimeStore();
    }

    /** run() with the given first screen: it becomes the main window (id 1). */
    private function runApp(?\RageKit\Screen $first = null): void
    {
        $this->app->first = $first;
        $this->app->run();
        FakeHost::$calls = [];
    }

    private function event(int $window, int $action, string $arg = ''): void
    {
        FakeHost::$calls = [];
        (FakeHost::$dispatcher)($window, $action, $arg);
    }

    public function testWindowObjectIsCreatedPerWindow(): void
    {
        $one = $this->app->open(new FramedScreen(), title: 'One');
        $two = $this->app->open(new FramedScreen(), title: 'Two');

        self::assertInstanceOf(FrameWindow::class, $one);
        self::assertInstanceOf(FrameWindow::class, $two);
        self::assertNotSame($one, $two);
        self::assertSame(1, $one->windowId());
        self::assertSame(2, $two->windowId());
        self::assertSame('Framed', $one->contextTitle());
        self::assertContains('string 1 1 Framed', FakeHost::$calls);
    }

    public function testWindowValuesArePushed(): void
    {
        $this->app->open(new FramedScreen(), title: 'One');
        $this->event(1, 3);
        self::assertSame(['string 1 2 searched'], FakeHost::$calls);
    }

    public function testMaximizedChangeFromOs(): void
    {
        $window = $this->app->open(new FramedScreen(), title: 'One');
        $this->event(1, -1, '1');
        self::assertTrue($window->maximized);
        self::assertSame(['bool 1 3 true'], FakeHost::$calls);
        $this->event(1, -1, '0');
        self::assertFalse($window->maximized);
    }

    public function testToastTickOnlyResyncs(): void
    {
        $screen = new CounterScreen();
        $this->app->open($screen, title: 'Counter');
        $this->event(1, -2);
        self::assertSame([], FakeHost::$calls, 'nothing changed, nothing pushed, no handler');
    }

    public function testWindowCloseButtonClosesWindow(): void
    {
        $this->runApp();
        $this->app->open(new FramedScreen(), title: 'Two');
        $this->event(2, 1);
        self::assertSame(['close 2'], FakeHost::$calls);
    }

    public function testRunOpensTheMainWindowThenStartsThenRuns(): void
    {
        $this->app->onStart = function (): void {
            FakeHost::$calls[] = 'start ' . $this->app->main()->id;
        };
        $this->app->run();
        self::assertSame(['open rk-screens "Main" 480x320', 'int 1 0 0', 'int 1 1 0', 'string 1 2 ', 'list 1 3', 'list end', 'string 1 5 ', 'start 1', 'run'], FakeHost::$calls);
    }

    /** Review Focus 1: closing the main window while About is open closes both and ends the app. */
    public function testClosingMainClosesEverything(): void
    {
        $this->runApp();
        $this->event(1, 3);
        $this->event(1, 0);
        self::assertSame(['close 1', 'close 2', 'quit'], FakeHost::$calls);
        $this->event(2, 1);
        self::assertSame([], FakeHost::$calls, 'a late event for a closed window does nothing');
    }

    /** Review Focus 2: a store event fired in a handler resets the main window in the same sync. */
    public function testListenerNavigatesDuringAHandler(): void
    {
        $this->app->onStart = function (): void {
            RuntimeStore::get()->session->on('signedOut', function (): void {
                $this->app->main()->navigator()->reset(new DetailScreen(9));
            });
        };
        $this->runApp();
        $this->event(1, 9, 'ann');
        self::assertSame(['string 1 5 ann'], FakeHost::$calls);
        $this->event(1, 10);
        self::assertSame(['int 1 0 2', 'int 1 4 9'], FakeHost::$calls);
        $this->event(1, 1); // a click the counter page sent before it went away
        self::assertSame([], FakeHost::$calls);
    }

    /** Review Focus 3: every window showing a store value follows a change made in one of them. */
    public function testTwoWindowsShowTheSameStoreValue(): void
    {
        $this->runApp();
        $this->app->open(new CounterScreen(), title: 'Second');
        FakeHost::$calls = [];
        $this->event(2, 9, 'ann');
        self::assertSame(['string 1 5 ann', 'string 2 5 ann'], FakeHost::$calls);
    }

    public function testOpenPushesInitialValues(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter', width: 360, height: 400);

        self::assertSame([
            'open rk-screens "Counter" 360x400',
            'int 1 0 0',
            'int 1 1 0',
            'string 1 2 ',
            'list 1 3',
            'list end',
            'string 1 5 ',
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
        self::assertSame(['int 1 0 0', 'int 1 1 0', 'string 1 2 ', 'list 1 3', 'list end', 'string 1 5 '], FakeHost::$calls);
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

        self::assertSame(['open rk-screens "About" 300x100', 'int 2 0 1'], FakeHost::$calls);
    }

    public function testClosingAnotherWindowKeepsTheApp(): void
    {
        $this->runApp();
        $this->event(1, 3);
        $this->event(2, 0);
        self::assertSame(['close 2'], FakeHost::$calls);
        $this->event(1, 0);
        self::assertSame(['close 1', 'quit'], FakeHost::$calls);
    }

    public function testPushSwitchesPageInTheSameWindow(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 4);
        // No new window: property 0 (rk-page) of window 1 switches to the detail page, then its values.
        self::assertSame(['int 1 0 2', 'int 1 4 0'], FakeHost::$calls);
    }

    public function testBackRestoresState(): void
    {
        $counter = new CounterScreen();
        $this->app->open($counter, title: 'Counter');
        $this->event(1, 1);
        $this->event(1, 1);
        $this->event(1, 4);
        $this->event(1, 5);
        self::assertSame(['int 1 4 3'], FakeHost::$calls);
        $this->event(1, 6);
        // Back on the counter page; its values were never changed, so only the page is sent.
        self::assertSame(['int 1 0 0'], FakeHost::$calls);
        self::assertSame(2, $counter->count);
    }

    /** Review Focus 1: a click from the page just left does nothing on the new one. */
    public function testStaleActionIsIgnored(): void
    {
        $counter = new CounterScreen();
        $this->app->open($counter, title: 'Counter');
        $this->event(1, 4);
        $this->event(1, 1);
        self::assertSame([], FakeHost::$calls);
        self::assertSame(0, $counter->count);
    }

    /** Review Focus 2: the same class twice keeps two states. */
    public function testSameClassTwice(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 4);
        $this->event(1, 5);
        $this->event(1, 8);
        // Same page (a DetailScreen again), its own value.
        self::assertSame(['int 1 4 100'], FakeHost::$calls);
        $this->event(1, -3);
        self::assertSame(['int 1 4 1'], FakeHost::$calls);
    }

    public function testActionMinus3GoesBack(): void
    {
        $this->app->open(new CounterScreen(), title: 'Counter');
        $this->event(1, 4);
        $this->event(1, -3);
        self::assertSame(['int 1 0 0'], FakeHost::$calls);
        $this->event(1, -3);
        self::assertSame([], FakeHost::$calls, 'back on the first screen does nothing');
    }

    public function testPushOtherWindowClassThrows(): void
    {
        $this->app->open(new WrongPushScreen(), title: 'W');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FramedScreen uses window FrameWindow; this window is Window');
        $this->event(1, 7);
    }

    /** The page is read after the values: reading them may change the stack (dev mode restoring it). */
    public function testPageFollowsAStackChangedWhileReadingValues(): void
    {
        $this->app->open(new RestoringScreen(), title: 'R');
        self::assertSame(['open rk-screens "R" 480x320', 'int 1 0 2', 'int 1 4 7'], FakeHost::$calls);
    }
}
