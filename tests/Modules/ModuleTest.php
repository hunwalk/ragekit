<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleRegistry;
use RageKit\Cli\RageKitException;

final class ModuleTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/modules/demo/module.yml';

    public function testLoadsFunctionsAndPaths(): void
    {
        $m = Module::load(self::FIXTURE, '/rk');
        self::assertSame('demo', $m->name);
        self::assertSame(['demo_echo', 'demo_add', 'demo_ping'], array_keys($m->functions));
        self::assertSame(['text' => 'string', 'times' => 'int'], $m->functions['demo_echo']->params);
        self::assertSame('string', $m->functions['demo_echo']->returns);
        self::assertNull($m->functions['demo_ping']->returns);
        $dir = (string) realpath(dirname(self::FIXTURE));
        self::assertSame(["$dir/src"], $m->php);
        self::assertSame(["$dir/native"], $m->library['sources']);
        self::assertSame(['m'], $m->library['link-libs']);
    }

    public function testRageKitVariableInPaths(): void
    {
        $m = Module::load(__DIR__ . '/../../modules/audio/module.yml', '/rk');
        self::assertContains((string) realpath(__DIR__ . '/../../modules/audio') . '/native/third_party/miniaudio', $m->library['include-paths']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'bad type' => ["name: d\nfunctions:\n  d_x: { params: { n: long } }\n", "functions.d_x.params.n: unknown type 'long' (use int, float, bool, string)"];
        yield 'bad return' => ["name: d\nfunctions:\n  d_x: { returns: array }\n", "functions.d_x.returns: unknown type 'array' (use int, float, bool, string)"];
        yield 'wrong prefix' => ["name: d\nfunctions:\n  other_x: {}\n", 'functions.other_x: function names must start with d_'];
        yield 'no name' => ["functions: {}\n", 'name is missing'];
        yield 'bad module name' => ["name: my-mod\nfunctions: {}\n", "name 'my-mod' must be lowercase letters, digits and _"];
        yield 'reserved param' => ["name: d\nfunctions:\n  d_x: { params: { fn: int } }\n", "functions.d_x.params.fn: 'fn' can't be a parameter name"];
        yield 'keyword param' => ["name: d\nfunctions:\n  d_x: { params: { class: int } }\n", "functions.d_x.params.class: 'class' can't be a parameter name"];
        yield 'odd param' => ["name: d\nfunctions:\n  d_x: { params: { 'my param': int } }\n", "functions.d_x.params.my param: 'my param' can't be a parameter name"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testRejectsInvalidDeclarations(string $yaml, string $message): void
    {
        $file = sys_get_temp_dir() . '/rk-mod-' . uniqid() . '.yml';
        file_put_contents($file, $yaml);
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage($message);
        Module::load($file, '/rk');
    }

    public function testDiscoveryOrderAndDuplicates(): void
    {
        $project = sys_get_temp_dir() . '/rk-modproj-' . uniqid();
        mkdir("$project/modules/demo", 0777, true);
        copy(self::FIXTURE, "$project/modules/demo/module.yml");
        $registry = ModuleRegistry::discover((string) realpath(__DIR__ . '/../..'), $project);
        self::assertSame(['audio', 'demo'], $registry->names());
        self::assertSame('demo', $registry->get('demo')->name);
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("unknown module 'nope' (installed: audio, demo)");
        $registry->get('nope');
    }

    public function testSameNameInTwoPlacesFails(): void
    {
        $project = sys_get_temp_dir() . '/rk-modproj-' . uniqid();
        mkdir("$project/modules/audio", 0777, true);
        file_put_contents("$project/modules/audio/module.yml", "name: audio\nfunctions: {}\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('two modules are named audio');
        ModuleRegistry::discover((string) realpath(__DIR__ . '/../..'), $project);
    }
}
