<?php

namespace RageKit;

/**
 * Runs a function later, once or repeatedly, on the UI thread like a click: after it runs, the
 * open windows re-read their values. An exception it throws goes to App::uncaughtException().
 *
 *     $this->poll = Timer::every(5, fn() => $this->refresh());
 *     Timer::after(0.5, fn() => $this->hint = '');
 *     $this->poll->stop();
 *
 * Timers start once an App exists (they need its backend) and only fire while it runs.
 */
final class Timer
{
    private static ?Backend $backend = null;

    private static int $next = 0;

    private bool $active = true;

    private function __construct(public readonly int $id, private \Closure $fn, private bool $repeat)
    {
    }

    /** Runs $fn every $seconds (more than 0; at least 1 ms) until stop(). */
    public static function every(float $seconds, \Closure $fn): Timer
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Timer::every() needs more than 0 seconds');
        }
        return self::start(max(1, (int) round($seconds * 1000)), $fn, true);
    }

    /** Runs $fn once, $seconds from now (0: on the event loop's next turn), unless stopped first. */
    public static function after(float $seconds, \Closure $fn): Timer
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Timer::after() needs 0 seconds or more');
        }
        return self::start((int) round($seconds * 1000), $fn, false);
    }

    /** Whether it will still run: false once stopped, and for after() once it ran. */
    public function active(): bool
    {
        return $this->active;
    }

    public function stop(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        Events::off('@timer', $this->id);
        self::$backend?->stopTimer($this->id);
    }

    /** @internal The App's constructor hands its backend over (null: none, in tests). */
    public static function attach(?Backend $backend): void
    {
        self::$backend = $backend;
    }

    private static function start(int $milliseconds, \Closure $fn, bool $repeat): Timer
    {
        $backend = self::$backend ?? throw new \LogicException('timers start once an App exists');
        $timer = new Timer(++self::$next, $fn, $repeat);
        Events::on('@timer', $timer->id, 'tick', function (string $data) use ($timer): void {
            $timer->tick();
        });
        $backend->startTimer($timer->id, $milliseconds, $repeat);
        return $timer;
    }

    private function tick(): void
    {
        if (!$this->active) {
            return;
        }
        if (!$this->repeat) {
            $this->stop();
        }
        ($this->fn)();
    }
}
