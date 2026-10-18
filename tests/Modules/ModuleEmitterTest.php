<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;

final class ModuleEmitterTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/modules/demo/module.yml';
    private const ROOT = __DIR__ . '/../..';

    private function module(): Module
    {
        return Module::load(self::FIXTURE, '/rk');
    }

    public function testHeaderDeclaresTheCAbi(): void
    {
        $h = ModuleEmitter::header($this->module());
        self::assertStringContainsString('#include "ragekit_module.h"', $h);
        self::assertStringContainsString('RKM_API const char *rkm_demo_echo(const char *text, size_t text_len, int64_t times);', $h);
        self::assertStringContainsString('RKM_API double rkm_demo_add(double a, double b, int round);', $h);
        self::assertStringContainsString('RKM_API void rkm_demo_ping(void);', $h);
        self::assertStringContainsString('RKM_API void rkm_demo_init(rk_post_fn post);', $h);
        self::assertStringContainsString('RKM_API void rkm_demo_reset(void);', $h);
        self::assertStringContainsString('RKM_API const char *rkm_demo_call(const char *function, const char *const *args, const size_t *lens, size_t count, int *ok);', $h);
    }

    public function testStubAndDevFunctions(): void
    {
        $m = $this->module();
        $stub = ModuleEmitter::stub($m);
        self::assertStringContainsString('function demo_echo(string $text, int $times): string {}', $stub);
        self::assertStringContainsString('function demo_add(float $a, float $b, bool $round): float {}', $stub);
        self::assertStringContainsString('function demo_ping(): void {}', $stub);
        $dev = ModuleEmitter::devFunctions($m);
        self::assertStringContainsString("function demo_add(float \$a, float \$b, bool \$round): float\n{\n    return (float) \\RageKit\\Slint\\Dev\\Session::call('demo_add', [var_export(\$a, true), var_export(\$b, true), \$round ? '1' : '0']);\n}", $dev);
        self::assertStringContainsString("function demo_echo(string \$text, int \$times): string\n{\n    return \\RageKit\\Slint\\Dev\\Session::call('demo_echo', [\$text, (string) \$times]);\n}", $dev);
        self::assertStringContainsString("function demo_ping(): void\n{\n    \\RageKit\\Slint\\Dev\\Session::call('demo_ping', []);\n}", $dev);
    }

    public function testHostLoadsTheLibraryAtRuntime(): void
    {
        $host = ModuleEmitter::host($this->module());
        self::assertStringContainsString('rk_module_open("demo")', $host);
        self::assertStringContainsString('init(rk_post_event);', $host);
        self::assertStringContainsString('const bool expected = (rk_module_expect("demo", "rkm_demo_echo\\nrkm_demo_add\\nrkm_demo_ping"), true);', $host);
        self::assertStringContainsString("String php_demo_echo(String text, Int times)\n{\n    static auto fn = reinterpret_cast<decltype(&rkm_demo_echo)>(rkm_symbol(\"rkm_demo_echo\"));\n    if (fn == nullptr) {\n        return String(\"\");\n    }\n    const char *result = fn(text.data(), text.length(), times);\n    return String(result != nullptr ? result : \"\");\n}", $host);
        self::assertStringContainsString('Float php_demo_add(Float a, Float b, Bool round)', $host);
        self::assertStringContainsString('return fn(a, b, round ? 1 : 0);', $host);
        self::assertStringContainsString("void php_demo_ping()\n{", $host);
    }

    public function testCallEntryRoundTripsThroughTheLibrary(): void
    {
        if (trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('g++ missing');
        }
        $dir = sys_get_temp_dir() . '/rk-modcall-' . uniqid();
        mkdir($dir);
        $m = $this->module();
        file_put_contents("$dir/rk_module_demo.h", ModuleEmitter::header($m));
        file_put_contents("$dir/rk_module_demo_call.cc", ModuleEmitter::callEntry($m));
        $bridge = self::ROOT . '/native/include';
        $this->run_('g++ -std=c++17 -shared -fPIC -fvisibility=hidden ' . escapeshellarg(dirname(self::FIXTURE) . '/native/demo.cc') . " $dir/rk_module_demo_call.cc -I$dir -I" . escapeshellarg($bridge) . " -o $dir/librk_module_demo.so");
        $this->run_('g++ -std=c++17 ' . escapeshellarg(self::ROOT . '/tests/fixtures/modules/probe_call.cc') . " -ldl -o $dir/probe");
        exec("$dir/probe $dir/librk_module_demo.so", $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        self::assertSame([
            '1|a\\tb éa\\tb é',
            '1|0.3',
            '1|',
            '0|unknown function demo_nope',
            '0|demo_echo takes 2 arguments, got 1',
            '1|',
        ], $lines);
    }

    private function run_(string $command): void
    {
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));
    }
}
