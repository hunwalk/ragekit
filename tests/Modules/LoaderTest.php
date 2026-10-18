<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;

/** The bridge's runtime module loader (native/slint/bridge/rk_modules.cc), built on its own. */
final class LoaderTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, string> */
    private function probe(?string $expect = null): array
    {
        if (trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('g++ missing');
        }
        $dir = sys_get_temp_dir() . '/rk-loader-' . uniqid();
        mkdir("$dir/mods", 0777, true);
        $root = (string) realpath(self::ROOT);
        $m = Module::load("$root/tests/fixtures/modules/demo/module.yml", $root);
        file_put_contents("$dir/rk_module_demo.h", ModuleEmitter::header($m));
        file_put_contents("$dir/rk_module_demo_call.cc", ModuleEmitter::callEntry($m));
        $this->sh("g++ -std=c++17 -shared -fPIC -fvisibility=hidden $root/tests/fixtures/modules/demo/native/demo.cc $dir/rk_module_demo_call.cc -I$dir -I$root/native/include -o $dir/mods/librk_module_demo.so");
        $this->sh("g++ -std=c++20 $root/tests/fixtures/modules/probe_loader.cc $root/native/slint/bridge/rk_modules.cc -I$root/native/slint/bridge -ldl -o $dir/probe");
        exec('RAGEKIT_MODULE_DIR=' . escapeshellarg("$dir/mods") . ' ' . escapeshellarg("$dir/probe") . ($expect === null ? '' : ' ' . escapeshellarg($expect)) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        $values = [];
        foreach ($lines as $line) {
            [$key, $value] = explode(' ', $line, 2) + [1 => ''];
            $values[$key] = $value;
        }
        $values['dir'] = $dir;
        return $values;
    }

    public function testLoadsALibraryAndItsSymbols(): void
    {
        $v = $this->probe();
        self::assertSame('1', $v['open-demo']);
        self::assertSame('1', $v['same-handle'], 'loaded once, cached');
        self::assertSame('1', $v['symbol']);
        self::assertSame('[]', $v['demo-error']);
        self::assertSame($v['dir'], $v['app-dir'], 'the folder of the running executable');
    }

    public function testMissingLibraryIsAnErrorNotACrash(): void
    {
        $v = $this->probe();
        self::assertSame('1', $v['open-nope']);
        self::assertStringContainsString('librk_module_nope.so', $v['nope-error']);
    }

    public function testMissingSymbolIsUnavailable(): void
    {
        self::assertSame('1', $this->probe()['missing-symbol']);
    }

    public function testLibraryLackingAnExpectedFunctionIsUnavailable(): void
    {
        $v = $this->probe("rkm_demo_add\nrkm_demo_gone");
        self::assertSame('0', $v['open-expected']);
        self::assertStringContainsString('rkm_demo_gone', $v['expected-error']);
        self::assertSame('1', $this->probe("rkm_demo_add\nrkm_demo_echo")['open-expected']);
    }

    private function sh(string $command): void
    {
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));
    }
}
