<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Navigator;
use RageKit\Screen;
use RageKit\Window;

/** A screen that writes its hooks to a shared log. */
class LoggedScreen extends Screen
{
    /** @var list<string> */
    public static array $log = [];

    public int $value = 0;

    public function __construct(public string $name = 'A')
    {
    }

    public function shown(): void
    {
        self::$log[] = "$this->name shown";
    }

    public function hidden(): void
    {
        self::$log[] = "$this->name hidden";
    }

    /** What a handler would do: reach the window's navigator. */
    public function nav(): Navigator
    {
        return $this->navigator();
    }

    public function win(): Window
    {
        return $this->currentWindow();
    }
}

final class OtherWindowScreen extends LoggedScreen
{
}

final class NavWindow extends Window
{
    public function current(): Screen
    {
        return $this->context;
    }
}

final class NavigatorTest extends TestCase
{
    protected function setUp(): void
    {
        LoggedScreen::$log = [];
    }

    private function navigator(Screen $root, ?Window $window = null): Navigator
    {
        $window ??= new Window();
        $window->attach(RecordingApp::make(), 1);
        $windowOf = fn(Screen $s): string => $s instanceof OtherWindowScreen ? 'rk-screens-OtherWindow' : 'rk-screens';
        return new Navigator($window, $root, $windowOf);
    }

    public function testPushShowsNewTopAndKeepsTheOld(): void
    {
        $a = new LoggedScreen('A');
        $a->value = 3;
        $nav = $this->navigator($a);
        $b = new LoggedScreen('B');
        $nav->push($b);
        self::assertSame($b, $nav->top());
        self::assertSame(2, $nav->depth());
        self::assertTrue($nav->canGoBack());
        self::assertTrue($nav->back());
        self::assertSame($a, $nav->top());
        self::assertSame(3, $a->value);
        self::assertFalse($nav->canGoBack());
    }

    public function testBackOnRootDoesNothing(): void
    {
        $a = new LoggedScreen('A');
        $nav = $this->navigator($a);
        self::assertFalse($nav->back());
        self::assertSame(1, $nav->depth());
        self::assertSame($a, $nav->top());
    }

    public function testReplaceAndReset(): void
    {
        $nav = $this->navigator(new LoggedScreen('A'));
        $nav->push(new LoggedScreen('B'));
        $c = new LoggedScreen('C');
        $nav->replace($c);
        self::assertSame($c, $nav->top());
        self::assertSame(2, $nav->depth());
        $d = new LoggedScreen('D');
        $nav->reset($d);
        self::assertSame($d, $nav->top());
        self::assertSame(1, $nav->depth());
        self::assertSame([$d], $nav->stack());
    }

    public function testHooksOrder(): void
    {
        $nav = $this->navigator(new LoggedScreen('A'));
        $nav->push(new LoggedScreen('B'));
        $nav->back();
        self::assertSame(['A shown', 'A hidden', 'B shown', 'B hidden', 'A shown'], LoggedScreen::$log);
    }

    public function testOtherWindowIsRefused(): void
    {
        $nav = $this->navigator(new LoggedScreen('A'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OtherWindowScreen uses window OtherWindow; this window is Window');
        $nav->push(new OtherWindowScreen('X'));
    }

    public function testSameClassTwice(): void
    {
        $nav = $this->navigator(new LoggedScreen('A'));
        $first = new LoggedScreen('P');
        $first->value = 1;
        $second = new LoggedScreen('P');
        $second->value = 2;
        $nav->push($first);
        $nav->push($second);
        $nav->back();
        self::assertSame($first, $nav->top());
        self::assertSame(1, $nav->top()->value);
    }

    public function testWindowFollowsTop(): void
    {
        $window = new NavWindow();
        $a = new LoggedScreen('A');
        $nav = $this->navigator($a, $window);
        self::assertSame($a, $window->current());
        $b = new LoggedScreen('B');
        $nav->push($b);
        self::assertSame($b, $window->current());
        self::assertTrue($window->canGoBack());
        $window->back();
        self::assertSame($a, $window->current());
        self::assertSame($window, $nav->window());
        self::assertSame($nav, $window->navigator());
    }

    public function testPushedScreensCanNavigateToo(): void
    {
        $nav = $this->navigator(new LoggedScreen('A'));
        $b = new LoggedScreen('B');
        $nav->push($b);
        self::assertSame($nav, $b->nav());
    }

    public function testNavigatorBeforeWindowIsAClearError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('LoggedScreen: no window yet; it exists once the screen is shown in one');
        (new LoggedScreen('A'))->nav();
    }
}
