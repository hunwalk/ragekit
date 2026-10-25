<?php

namespace Fix;

use RageKit\App;
use RageKit\RootStore;
use RageKit\Screen;
use RageKit\Timer;

/**
 * Echoes what its timers do (tests/e2e/timer.sh reads it): a one-shot started before the window
 * opens, one stopped before it fires, one that throws, and one that ticks five times a second
 * after a second's wait and then stops itself.
 */
final class TimerApp extends App
{
    private ClockScreen $clock;

    private ?Timer $poll = null;

    protected function createStore(): RootStore
    {
        Timer::after(0, function (): void {
            echo "before the window\n";
        });
        return parent::createStore();
    }

    protected function firstScreen(): Screen
    {
        $this->clock = new ClockScreen();
        return $this->clock;
    }

    protected function title(): string
    {
        return 'timer';
    }

    protected function start(): void
    {
        $never = Timer::after(0.1, function (): void {
            echo "never\n";
        });
        $never->stop();
        Timer::after(0.05, function (): void {
            throw new \RuntimeException('boom');
        });
        Timer::after(1, function (): void {
            $this->clock->status = 'ticking';
            $this->poll = Timer::every(0.2, function (): void {
                $this->clock->ticks++;
                $this->clock->status = 'tick ' . $this->clock->ticks;
                echo 'tick ' . $this->clock->ticks . "\n";
                if ($this->clock->ticks === 5) {
                    $this->poll?->stop();
                    $this->clock->status = 'stopped';
                    echo "stopped\n";
                }
            });
        });
    }
}
