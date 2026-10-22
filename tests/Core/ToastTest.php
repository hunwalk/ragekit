<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\App;
use RageKit\Layout;
use RageKit\Navigator;
use RageKit\Screen;

final class ToastScreen extends Screen
{
    public function save(): void
    {
        $this->toast('Saved');
        $this->toast('Synced');
    }
}

final class ToastLayout extends Layout
{
    public function hello(): void
    {
        $this->toast('From the title bar');
    }
}

final class ToastTest extends TestCase
{
    private float $clock = 100.0;

    private function navigator(Screen $root, ?Layout $layout = null): Navigator
    {
        $app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
        return new Navigator($app, $root, $layout, fn(Screen $s): string => 'rk-screens', fn(): float => $this->clock);
    }

    public function testToastsShowForThreeSeconds(): void
    {
        $screen = new ToastScreen();
        $nav = $this->navigator($screen);
        self::assertSame([], $nav->toastTexts());
        $screen->save();
        self::assertSame(['Saved', 'Synced'], $nav->toastTexts());
        $this->clock += 2.9;
        self::assertSame(['Saved', 'Synced'], $nav->toastTexts());
        $screen->toast('Later');
        $this->clock += 0.2;
        self::assertSame(['Later'], $nav->toastTexts());
        $this->clock += 3.0;
        self::assertSame([], $nav->toastTexts());
    }

    public function testLayoutToastsShowToo(): void
    {
        $screen = new ToastScreen();
        $layout = new ToastLayout();
        $nav = $this->navigator($screen, $layout);
        $layout->hello();
        $screen->toast('Mine');
        self::assertSame(['Mine', 'From the title bar'], $nav->toastTexts());
    }

    /** Review Focus 4: a toast fired right before push() stays on the next screen. */
    public function testToastSurvivesPush(): void
    {
        $a = new ToastScreen();
        $nav = $this->navigator($a);
        $a->toast('Saved');
        $nav->push(new ToastScreen());
        self::assertSame(['Saved'], $nav->toastTexts());
        $this->clock += 3.0;
        self::assertSame([], $nav->toastTexts());
    }

    /** A covered screen's toast still shows (e.g. fired from a handler the top screen called). */
    public function testCoveredScreensToastShows(): void
    {
        $a = new ToastScreen();
        $nav = $this->navigator($a);
        $nav->push(new ToastScreen());
        $a->toast('Background');
        self::assertSame(['Background'], $nav->toastTexts());
    }

    /** A toast fired by the screen that leaves the stack still shows (e.g. "Saved" then back()). */
    public function testToastSurvivesBackReplaceAndReset(): void
    {
        $nav = $this->navigator(new ToastScreen());
        $b = new ToastScreen();
        $nav->push($b);
        $b->toast('Saved');
        $nav->back();
        $c = new ToastScreen();
        $nav->push($c);
        $c->toast('Replaced');
        $nav->replace(new ToastScreen());
        $d = new ToastScreen();
        $nav->push($d);
        $d->toast('Welcome');
        $nav->reset(new ToastScreen());
        self::assertSame(['Saved', 'Replaced', 'Welcome'], $nav->toastTexts());
    }
}
