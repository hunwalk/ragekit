<?php

declare(strict_types=1);

namespace RageKit\Tests\Kits;

use PHPUnit\Framework\TestCase;
use RageKit\Tests\Support\InstalledToolchain;

/**
 * Runs Material's RkSlider headlessly (Slint testing backend, tests/fixtures/slint-probe) with
 * min 50 / max 100, and checks what PHP would receive.
 */
final class MaterialSliderTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testSliderHonoursMinimumAndDoesNotEchoPushedValues(): void
    {
        $slint = InstalledToolchain::slintHome();
        \RageKit\Tests\Support\Fetched::kit('material');
        $material = realpath(self::ROOT . '/kits/material/library/material.slint');
        if (!is_file("$slint/bin/slint-compiler") || $material === false || trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('slint-compiler, the Material library or g++ is not installed');
        }
        $dir = sys_get_temp_dir() . '/rk-slider-' . uniqid();
        mkdir($dir);
        $probe = self::ROOT . '/tests/fixtures/slint-probe';
        file_put_contents("$dir/slider.slint", str_replace('@KIT@', (string) realpath(self::ROOT . '/kits/material/rk-material.slint'), (string) file_get_contents("$probe/slider.slint")));
        $run = function (string $command): string {
            exec($command . ' 2>&1', $output, $code);
            self::assertSame(0, $code, $command . "\n" . implode("\n", $output));
            return implode("\n", $output);
        };
        $run('SLINT_EMIT_DEBUG_INFO=1 ' . escapeshellarg("$slint/bin/slint-compiler") . ' ' . escapeshellarg("$dir/slider.slint") . ' -f cpp -L material=' . escapeshellarg($material) . ' -o ' . escapeshellarg("$dir/slider_probe.h"));
        $run('g++ -std=c++20 -O0 -DSLINT_FEATURE_EXPERIMENTAL ' . escapeshellarg("$probe/slider_probe.cc") . ' -I' . escapeshellarg($dir) . ' -I' . escapeshellarg("$slint/include/slint")
            . ' -L' . escapeshellarg("$slint/lib") . ' -lslint_cpp -Wl,-rpath,' . escapeshellarg("$slint/lib") . ' -o ' . escapeshellarg("$dir/probe"));
        $out = $run(escapeshellarg("$dir/probe"));

        self::assertStringContainsString('after-set changes=0', $out, 'a value pushed from PHP must not come back as value_changed');
        self::assertStringContainsString('after-press value=87.5 changes=1', $out, 'pressing 3/4 along a 50..100 slider gives 87.5');
        self::assertStringContainsString('disabled=0', $out);
    }
}
