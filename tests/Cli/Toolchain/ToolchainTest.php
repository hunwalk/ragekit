<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Toolchain\Gcc;
use RageKit\Cli\Toolchain\Platform;
use RageKit\Tests\Support\InstalledToolchain;

final class ToolchainTest extends TestCase
{
    protected function setUp(): void
    {
        if (Platform::os() !== 'linux') {
            self::markTestSkipped('Linux toolchain');
        }
    }

    public function testLinuxUsesGcc(): void
    {
        self::assertInstanceOf(Gcc::class, InstalledToolchain::toolchain()->compiler());
    }

    public function testNanoSkipsPhpBuilder(): void
    {
        $defaults = InstalledToolchain::toolchain()->defaults(nano: true);
        self::assertArrayNotHasKey('php-builder', $defaults);
        self::assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $defaults['php-version']);
    }

    public function testCaptureReportsElapsedSecondsWhileTheCommandRuns(): void
    {
        $ticks = [];
        [$code, $output] = InstalledToolchain::toolchain()->capture(['sh', '-c', 'echo start; sleep 2.3; echo done'], sys_get_temp_dir(), function (int $seconds) use (&$ticks): void {
            $ticks[] = $seconds;
        });
        self::assertSame(0, $code);
        self::assertSame("start\ndone\n", $output);
        self::assertSame([1, 2], $ticks);
    }
}
