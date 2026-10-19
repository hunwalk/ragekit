<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;

/**
 * Double-clicks on a title bar are found from two presses: the OS's or window manager's move loop
 * eats the releases, so Slint's double-clicked never fires there (native/slint/window/double_click.h).
 */
final class DoubleClickTest extends TestCase
{
    public function testTwoQuickNearbyPressesAreADoubleClick(): void
    {
        if (trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('needs g++');
        }
        $root = (string) realpath(__DIR__ . '/../..');
        $exe = sys_get_temp_dir() . '/rk-dblclick-' . uniqid();
        exec('g++ -std=c++17 -Wall -Werror -I' . escapeshellarg("$root/native/slint/window") . ' '
            . escapeshellarg("$root/tests/fixtures/window/double_click_probe.cc") . ' -o ' . escapeshellarg($exe) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $result = trim((string) shell_exec(escapeshellarg($exe)));
        unlink($exe);
        // presses: double, third starts over, too far (2), too slow (2)
        self::assertSame('0100000', $result);
    }
}
