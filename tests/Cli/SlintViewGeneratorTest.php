<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\BackendModule;
use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Project\Project;
use RageKit\Cli\Slint\SlintViewGenerator;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\ViewProject;
use Symfony\Component\Console\Output\BufferedOutput;
use RageKit\Tests\Support\InstalledToolchain;

final class SlintViewGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-gen-e2e-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob("$this->dir/*/*") ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob("$this->dir/*") ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testGenerateReturnsBinderAndSkipsCompilerWhenUnchanged(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $backend = BackendModule::find($root, 'slint', InstalledToolchain::dir());
        if (!is_file($backend->views['slint-compiler']) || !\RageKit\Tests\Support\Fetched::kit('material') || !is_file("$root/kits/material/library/material.slint")) {
            self::markTestSkipped('slint-compiler or the Material library is missing');
        }

        file_put_contents("$this->dir/project.yml", "name: demo\nragekit:\n  backend: slint\n");
        file_put_contents("$this->dir/S.rk", '<VStack><Label text="Hi" /></VStack>');
        $classes = ['Demo\S' => new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", [], [], [])];
        $kits = KitRegistry::load([$root . '/kits/material/kit.yml'], $root);
        $out = "$this->dir/generated";
        $context = new BuildContext(Project::load($this->dir), $backend, InstalledToolchain::toolchain(), new BufferedOutput());
        $project = new ViewProject($classes, $kits, 'material', $out);
        $generator = new SlintViewGenerator();

        $first = $generator->generate($project, $context);
        self::assertSame(["$out/RageKit_Generated_Screens.php"], $first->phpSources);
        self::assertFileExists("$out/RageKit_Generated_Screens.php");
        self::assertFileExists("$out/app.h");
        self::assertStringNotContainsString('(unchanged)', $first->summary);

        $mtime = filemtime("$out/app.h");
        $second = $generator->generate($project, $context);
        self::assertSame(["$out/RageKit_Generated_Screens.php"], $second->phpSources);
        self::assertStringContainsString('(unchanged)', $second->summary);
        clearstatcache();
        self::assertSame($mtime, filemtime("$out/app.h"));
    }
}
