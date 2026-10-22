<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\App;
use RageKit\Layout;
use RageKit\Navigator;
use RageKit\Screen;

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
}

final class OtherLayoutScreen extends LoggedScreen
{
}

final class NavLayout extends Layout
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

    private function navigator(Screen $root, ?Layout $layout = null): Navigator
    {
        $app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
        $windowOf = fn(Screen $s): string => $s instanceof OtherLayoutScreen ? 'rk-screens-OtherLayout' : 'rk-screens';
        return new Navigator($app, $root, $layout, $windowOf);
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

    public function testOtherLayoutIsRefused(): void
    {
        $nav = $this->navigator(new LoggedScreen('A'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("OtherLayoutScreen uses layout OtherLayout; this window's layout is no layout");
        $nav->push(new OtherLayoutScreen('X'));
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

    public function testLayoutFollowsTop(): void
    {
        $layout = new NavLayout();
        $a = new LoggedScreen('A');
        $nav = $this->navigator($a, $layout);
        self::assertSame($a, $layout->current());
        $b = new LoggedScreen('B');
        $nav->push($b);
        self::assertSame($b, $layout->current());
        self::assertTrue($layout->canGoBack());
        $layout->back();
        self::assertSame($a, $layout->current());
        self::assertSame($layout, $nav->layout());
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
        $this->expectExceptionMessage('LoggedScreen: no navigator yet; it exists once the screen is shown in a window');
        (new LoggedScreen('A'))->nav();
    }
}
