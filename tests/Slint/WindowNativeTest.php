<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Tests\Support\InstalledToolchain;

/** Builds tests/fixtures/layouts (a screen in a layout) for real: the bridge gets the window code. */
final class WindowNativeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('builds with the Linux toolchain');
        }
        $this->dir = sys_get_temp_dir() . '/rk-layouts-' . uniqid();
        exec('cp -r ' . escapeshellarg(__DIR__ . '/../fixtures/layouts') . ' ' . escapeshellarg($this->dir));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testLayoutScreenBridgeBuilds(): void
    {
        $command = 'cd ' . escapeshellarg($this->dir) . ' && RAGEKIT_TOOLCHAIN=' . escapeshellarg(InstalledToolchain::dir())
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(InstalledToolchain::root() . '/bin/ragekit') . ' build --nano 2>&1';
        exec($command, $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        $bridge = "$this->dir/build/slint/libragekit_slint.so";
        self::assertFileExists("$this->dir/build/slint/layouts");
        exec('nm -D --defined-only ' . escapeshellarg($bridge), $symbols);
        $symbols = implode("\n", $symbols);
        self::assertStringContainsString('rk_window_minimize', $symbols);
        self::assertStringContainsString('rk_window_set_maximized', $symbols);
        exec('nm -C ' . escapeshellarg($bridge), $all);
        self::assertStringContainsString('rk::win::start_move', implode("\n", $all));
    }
}
