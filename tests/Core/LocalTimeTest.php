<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\LocalTime;

final class LocalTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        LocalTime::attach(null);
    }

    public function testFormatsInTheComputersTimezone(): void
    {
        $backend = new RecordingBackend();
        $backend->offset = 7200;
        LocalTime::attach($backend);
        $noonUtc = (int) strtotime('2026-07-01T12:00:00Z');
        self::assertSame('14:00', LocalTime::format('H:i', $noonUtc));
        self::assertSame(7200, LocalTime::offset($noonUtc));
    }

    public function testZoneLettersAreRefusedUnlessEscaped(): void
    {
        self::assertSame('12:00 T', LocalTime::format('H:i \\T', (int) strtotime('2026-07-01T12:00:00Z')));
        $this->expectException(\InvalidArgumentException::class);
        LocalTime::format('H:i T', 0);
    }

    public function testUtcWithoutAnApp(): void
    {
        self::assertSame('12:00', LocalTime::format('H:i', (int) strtotime('2026-07-01T12:00:00Z')));
    }
}
