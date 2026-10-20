<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Layout;
use RageKit\Screen;

final class ToastScreen extends Screen
{
    public float $clock = 100.0;

    public function save(): void
    {
        $this->toast('Saved');
        $this->toast('Synced');
    }

    protected function now(): float
    {
        return $this->clock;
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
    public function testToastsShowForThreeSeconds(): void
    {
        $screen = new ToastScreen();
        self::assertSame([], $screen->toastTexts());
        $screen->save();
        self::assertSame(['Saved', 'Synced'], $screen->toastTexts());
        $screen->clock += 2.9;
        self::assertSame(['Saved', 'Synced'], $screen->toastTexts());
        $screen->toast('Later');
        $screen->clock += 0.2;
        self::assertSame(['Later'], $screen->toastTexts());
        $screen->clock += 3.0;
        self::assertSame([], $screen->toastTexts());
    }

    public function testLayoutToastsShowToo(): void
    {
        $screen = new ToastScreen();
        $layout = new ToastLayout();
        $screen->useLayout($layout);
        $layout->hello();
        $screen->toast('Mine');
        self::assertSame(['Mine', 'From the title bar'], $screen->toastTexts());
    }
}
