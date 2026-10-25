<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Tests\Support\InstalledToolchain;

/** Builds tests/fixtures/rows (a collection with images, a list shown as images) for real: glue, bridge and host link. */
final class RowsNativeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('builds with the Linux toolchain');
        }
        $this->dir = sys_get_temp_dir() . '/rk-rows-' . uniqid();
        exec('cp -r ' . escapeshellarg(__DIR__ . '/../fixtures/rows') . ' ' . escapeshellarg($this->dir));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testRowsBuild(): void
    {
        $command = 'cd ' . escapeshellarg($this->dir) . ' && RAGEKIT_TOOLCHAIN=' . escapeshellarg(InstalledToolchain::dir())
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(InstalledToolchain::root() . '/bin/ragekit') . ' build --nano 2>&1';
        exec($command, $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        $bridge = "$this->dir/build/slint/libragekit_slint.so";
        self::assertFileExists("$this->dir/build/slint/rows");
        exec('nm -D --defined-only ' . escapeshellarg($bridge), $symbols);
        foreach (['rk_rows_begin', 'rk_rows_next', 'rk_rows_string', 'rk_rows_int', 'rk_rows_float', 'rk_rows_bool', 'rk_rows_image_asset', 'rk_rows_image_file', 'rk_rows_end'] as $symbol) {
            self::assertStringContainsString($symbol, implode("\n", $symbols));
        }
    }
}
