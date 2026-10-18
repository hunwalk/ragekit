<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Events;

final class EventsTest extends TestCase
{
    public function testDeliversToTheMatchingHandlerOnly(): void
    {
        $got = [];
        Events::on('audio', 1, 'finished', function (string $data) use (&$got): void { $got[] = "1 finished $data"; });
        Events::on('audio', 1, 'progress', function (string $data) use (&$got): void { $got[] = "1 progress $data"; });
        Events::on('audio', 2, 'finished', function (string $data) use (&$got): void { $got[] = "2 finished"; });
        Events::deliver('audio', 1, 'progress', '0.250');
        Events::deliver('audio', 1, 'finished', '');
        Events::deliver('audio', 3, 'finished', '');
        Events::deliver('other', 1, 'finished', '');
        self::assertSame(['1 progress 0.250', '1 finished '], $got);
        Events::off('audio', 1);
        Events::off('audio', 2);
    }

    public function testOffRemovesEveryKindOfAHandle(): void
    {
        $got = 0;
        Events::on('audio', 7, 'finished', function (string $data) use (&$got): void { $got++; });
        Events::on('audio', 7, 'progress', function (string $data) use (&$got): void { $got++; });
        Events::off('audio', 7);
        Events::deliver('audio', 7, 'finished', '');
        Events::deliver('audio', 7, 'progress', '');
        self::assertSame(0, $got);
    }

    public function testHandlerMayUnregisterWhileRunning(): void
    {
        $got = 0;
        Events::on('audio', 8, 'finished', function (string $data) use (&$got): void { $got++; Events::off('audio', 8); });
        Events::on('audio', 8, 'finished', function (string $data) use (&$got): void { $got++; });
        Events::deliver('audio', 8, 'finished', '');
        Events::deliver('audio', 8, 'finished', '');
        self::assertSame(2, $got, 'both ran once; nothing after off()');
    }
}
