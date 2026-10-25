<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Events;
use RageKit\Timer;

final class TimerTest extends TestCase
{
    private RecordingBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new RecordingBackend();
        Timer::attach($this->backend);
    }

    protected function tearDown(): void
    {
        Timer::attach(null);
    }

    public function testEveryRunsOnEachTickUntilStopped(): void
    {
        $ticks = 0;
        $timer = Timer::every(5, function () use (&$ticks): void { $ticks++; });
        self::assertSame(["timer-start $timer->id 5000 1"], $this->backend->calls);
        self::assertTrue($timer->active());

        Events::deliver('@timer', $timer->id, 'tick', '');
        Events::deliver('@timer', $timer->id, 'tick', '');
        self::assertSame(2, $ticks);

        $timer->stop();
        self::assertFalse($timer->active());
        self::assertSame("timer-stop $timer->id", $this->backend->calls[1]);
        Events::deliver('@timer', $timer->id, 'tick', '');
        self::assertSame(2, $ticks, 'a stopped timer never runs again');

        $timer->stop();
        self::assertCount(2, $this->backend->calls, 'stopping twice tells the backend once');
    }

    public function testAfterRunsOnceAndFreesItsNativeTimer(): void
    {
        $runs = 0;
        $timer = Timer::after(0.25, function () use (&$runs, &$timer): void {
            $runs++;
            self::assertFalse($timer->active(), 'inactive while its function runs');
        });
        self::assertSame(["timer-start $timer->id 250 0"], $this->backend->calls);

        Events::deliver('@timer', $timer->id, 'tick', '');
        Events::deliver('@timer', $timer->id, 'tick', '');
        self::assertSame(1, $runs);
        self::assertSame(["timer-start $timer->id 250 0", "timer-stop $timer->id"], $this->backend->calls);
    }

    public function testAfterZeroRunsOnTheNextTurnOfTheEventLoop(): void
    {
        $timer = Timer::after(0, function (): void {});
        self::assertSame(["timer-start $timer->id 0 0"], $this->backend->calls);
    }

    public function testEveryMayStopItselfFromItsFunction(): void
    {
        $ticks = 0;
        $timer = Timer::every(0.1, function () use (&$ticks, &$timer): void {
            if (++$ticks === 2) {
                $timer->stop();
            }
        });
        for ($i = 0; $i < 4; $i++) {
            Events::deliver('@timer', $timer->id, 'tick', '');
        }
        self::assertSame(2, $ticks);
    }

    public function testTimersGetTheirOwnIds(): void
    {
        $a = Timer::every(1, function (): void {});
        $b = Timer::every(1, function (): void {});
        self::assertNotSame($a->id, $b->id);
        $a->stop();
        $b->stop();
    }

    public function testIntervalsRoundToWholeMilliseconds(): void
    {
        $timer = Timer::every(0.0004, function (): void {});
        self::assertSame("timer-start $timer->id 1 1", $this->backend->calls[0], 'every() waits at least 1 ms');
        $timer->stop();
    }

    public function testEveryNeedsAPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::every(0, function (): void {});
    }

    public function testAfterRejectsNegativeDelays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::after(-1, function (): void {});
    }

    public function testNeedsAnApp(): void
    {
        Timer::attach(null);
        $this->expectException(\LogicException::class);
        Timer::every(1, function (): void {});
    }
}
