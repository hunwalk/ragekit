<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Navigator;
use RageKit\Screen;
use RageKit\Window;

final class ToastScreen extends Screen
{
    public function save(): void
    {
        $this->toast('Saved');
        $this->toast('Synced');
    }
}

final class ToastWindow extends Window
{
    public float $clock = 100.0;

    protected function now(): float
    {
        return $this->clock;
    }

    public function hello(): void
    {
        $this->toast('From the title bar');
    }
}

final class ToastTest extends TestCase
{
    private ToastWindow $window;

    private function navigator(Screen $root): Navigator
    {
        $this->window = new ToastWindow();
        $this->window->attach(RecordingApp::make(), 1);
        return new Navigator($this->window, $root, fn(Screen $s): string => 'rk-screens');
    }

    public function testToastsShowForThreeSeconds(): void
    {
        $screen = new ToastScreen();
        $nav = $this->navigator($screen);
        self::assertSame([], $this->window->toastTexts());
        $screen->save();
        self::assertSame(['Saved', 'Synced'], $this->window->toastTexts());
        $this->window->clock += 2.9;
        self::assertSame(['Saved', 'Synced'], $this->window->toastTexts());
        $screen->toast('Later');
        $this->window->clock += 0.2;
        self::assertSame(['Later'], $this->window->toastTexts());
        $this->window->clock += 3.0;
        self::assertSame([], $this->window->toastTexts());
    }

    public function testWindowToastsShowToo(): void
    {
        $screen = new ToastScreen();
        $nav = $this->navigator($screen);
        $this->window->hello();
        $screen->toast('Mine');
        self::assertSame(['Mine', 'From the title bar'], $this->window->toastTexts());
    }

    /** Review Focus 4: a toast fired right before push() stays on the next screen. */
    public function testToastSurvivesPush(): void
    {
        $a = new ToastScreen();
        $nav = $this->navigator($a);
        $a->toast('Saved');
        $nav->push(new ToastScreen());
        self::assertSame(['Saved'], $this->window->toastTexts());
        $this->window->clock += 3.0;
        self::assertSame([], $this->window->toastTexts());
    }

    /** A covered screen's toast still shows (e.g. fired from a handler the top screen called). */
    public function testCoveredScreensToastShows(): void
    {
        $a = new ToastScreen();
        $nav = $this->navigator($a);
        $nav->push(new ToastScreen());
        $a->toast('Background');
        self::assertSame(['Background'], $this->window->toastTexts());
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
        self::assertSame(['Saved', 'Replaced', 'Welcome'], $this->window->toastTexts());
    }
}
