<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Navigator;
use RageKit\Screen;
use RageKit\Window;

final class WindowTest extends TestCase
{
    private RecordingApp $app;

    protected function setUp(): void
    {
        $this->app = RecordingApp::make();
    }

    private function window(?Screen $root = null): Window
    {
        $window = new Window();
        $window->attach($this->app, 7);
        new Navigator($window, $root ?? new LoggedScreen('A'), fn(Screen $s): string => 'rk-screens');
        return $window;
    }

    public function testMinimizeAsksBackendForItsWindow(): void
    {
        $this->window()->minimize();
        self::assertSame(['minimize 7'], $this->app->recorder->calls);
    }

    public function testToggleMaximizeFlipsFlagAndBackend(): void
    {
        $window = $this->window();
        $window->toggleMaximize();
        self::assertTrue($window->maximized);
        $window->toggleMaximize();
        self::assertFalse($window->maximized);
        self::assertSame(['maximize 7 1', 'maximize 7 0'], $this->app->recorder->calls);
    }

    public function testCloseClosesItsWindow(): void
    {
        $this->window()->close();
        self::assertSame(['close 7'], $this->app->recorder->calls);
    }

    public function testScreenDefaultsToThePlainWindow(): void
    {
        $screen = new class extends Screen {
        };
        self::assertSame(Window::class, $screen::window());
        self::assertSame('', Window::template());
        self::assertSame('', Window::context());
    }

    public function testBackAndCanGoBack(): void
    {
        $window = $this->window();
        self::assertFalse($window->canGoBack());
        $window->navigator()->push(new LoggedScreen('B'));
        self::assertTrue($window->canGoBack());
        $window->back();
        self::assertFalse($window->canGoBack());
        self::assertSame('A', $window->navigator()->top()->name);
    }

    public function testScreensReachTheirWindow(): void
    {
        $a = new LoggedScreen('A');
        $window = $this->window($a);
        self::assertSame($window, $a->win());
        self::assertSame($window->navigator(), $a->nav());
    }

    public function testNavigatorBeforeOpenIsAClearError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('this window has no navigator yet; it exists once the window is open');
        (new Window())->navigator();
    }

    public function testCanGoBackBeforeOpenIsFalse(): void
    {
        self::assertFalse((new Window())->canGoBack());
    }
}
