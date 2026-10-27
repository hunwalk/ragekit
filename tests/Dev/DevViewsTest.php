<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Dev\DevViews;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\ViewProject;

final class DevViewsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testWritesEntriesKitCopiesAndJson(): void
    {
        $out = sys_get_temp_dir() . '/rk-dev-' . uniqid();
        $dir = sys_get_temp_dir() . '/rk-devsrc-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/S.rk", '<VStack><Label text="{name}" /><Button text="Go" variant="ghost" /></VStack>');
        $class = new ClassInfo('Demo\\S', 'S', "$dir/S.php", true, "$dir/S.rk", ['name' => 'string']);
        $root = (string) realpath(self::ROOT);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], $root);
        $result = DevViews::write(new ViewProject(['Demo\\S' => $class], $kits, 'material', $out, $dir), $out);

        self::assertSame(['rk-screens' => "$out/screens/rk-screens.slint"], $result->entries);
        self::assertSame("export { rk-screens, RkRich, RkEmoji } from \"$out/app.slint\";\n", file_get_contents("$out/screens/rk-screens.slint"));
        $app = (string) file_get_contents("$out/app.slint");
        self::assertStringContainsString("from \"$out/kits/material/rk-material.slint\"", $app);
        self::assertStringContainsString('in property <bool> rk-on-top;', $app);
        $kit = (string) file_get_contents("$out/kits/material/rk-material.slint");
        self::assertStringNotContainsString('"@material"', $kit);
        self::assertStringContainsString("\"$root/kits/material/library/material.slint\"", $kit);
        self::assertSame('S', json_decode((string) file_get_contents($result->screensJson), true)['windows'][0]['pages'][0]['name']);
        self::assertContains("$dir/S.rk", $result->watch);
        self::assertContains("$root/kits/material/rk-material.slint", $result->watch);
        self::assertTrue($result->changed);
        $again = DevViews::write(new ViewProject(['Demo\\S' => $class], $kits, 'material', $out, $dir), $out);
        self::assertFalse($again->changed, 'nothing the shell loads changed');
    }
}
