<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Tests\Support\Fetched;
use RageKit\Tests\Support\InstalledToolchain;

/**
 * <Entry onSubmit> and <Textarea onSubmit maxRows>: Return runs a handler (in a running app:
 * tests/e2e/scroll.sh sends messages). A Textarea sends on Return only when onSubmit is used.
 */
final class EntrySubmitTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testReturnRunsTheHandlerWithBothKits(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler) || !Fetched::kit('material')) {
            self::markTestSkipped('needs the Slint toolchain and Slint\'s Material library');
        }
        $dir = sys_get_temp_dir() . '/rk-submit-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/S.rk", '<VStack><Entry value="{{ $draft }}" onSubmit="send" />'
            . '<Textarea value="{{ $draft }}" rows="1" maxRows="5" onSubmit="send" /><Textarea value="{{ $draft }}" /></VStack>');
        $class = new ClassInfo('Demo\S', 'S', "$dir/S.php", true, "$dir/S.rk", props: ['draft' => 'string'], handlers: ['send' => []]);
        $root = (string) realpath(self::ROOT);
        $kits = KitRegistry::load(["$root/kits/material/kit.yml", "$root/kits/shadcn/kit.yml"], $root);
        foreach (['shadcn', 'material'] as $kit) {
            $out = (new SlintCompiler($kits, $kit))->compile(['Demo\S' => $class]);
            self::assertSame(2, preg_match_all('/submitted => \{ root\.action\(\d+, ""\); \}/', $out->slint), $kit);
            self::assertSame(1, substr_count($out->slint, 'submit-on-return: true;'), "$kit: only the Textarea with onSubmit sends on Return");
            self::assertStringContainsString('max-rows: 5;', $out->slint);
            file_put_contents("$dir/app.slint", $out->slint);
            $command = [$compiler, "$dir/app.slint", '-f', 'cpp', '-o', "$dir/app.h"];
            foreach ($out->libraries as $name => $path) {
                array_push($command, '-L', "$name=$path");
            }
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, "$kit:\n" . implode("\n", $lines));
        }
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
