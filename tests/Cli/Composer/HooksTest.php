<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Composer;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Composer\Hooks;

final class HooksTest extends TestCase
{
    public function testDoesNothingWhenNothingIsMissing(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('depends on this machine');
        }
        $event = new class {
            public bool $asked = false;

            public function getIO(): object
            {
                $this->asked = true;
                return new \stdClass();
            }
        };
        Hooks::offerBuildTools($event);
        self::assertFalse($event->asked);
    }
}
