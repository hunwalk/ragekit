<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Command\EjectCommand;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\KitEjector;
use RageKit\Cli\Templates\KitRegistry;
use RageKit\Tests\Support\InstalledToolchain;
use Symfony\Component\Console\Tester\CommandTester;

final class EjectTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/rk-eject-' . uniqid();
        mkdir($this->project);
        file_put_contents("$this->project/project.yml", "# my app\nname: gallery\nsources:\n  - main.php\n\nragekit:\n  backend: slint   # the renderer\n  kit: shadcn\n");
    }

    private function eject(string $kit, ?string $name = null, bool $use = true): \RageKit\Cli\Templates\EjectResult
    {
        return (new KitEjector((string) realpath(self::ROOT)))->eject($this->project, $kit, $name, $use);
    }

    public function testEjectShadcnRenamesAndSwitches(): void
    {
        $result = $this->eject('shadcn');
        self::assertSame('gallery', $result->name);
        $dir = "$this->project/kits/gallery";
        $yml = (string) file_get_contents("$dir/kit.yml");
        $slint = (string) file_get_contents("$dir/shadcn.slint");
        self::assertStringContainsString('name: gallery', $yml);
        self::assertMatchesRegularExpression('/^# Ejected from the shadcn kit of RageKit \S+: this copy is your app\'s code now\./m', $yml);
        self::assertStringContainsString('component: GalleryButton', $yml);
        self::assertStringContainsString('window: GalleryWindow', $yml);
        self::assertStringContainsString('export component GalleryButton', $slint);
        self::assertStringContainsString('component GalleryWindowButton', $slint, 'internal components are renamed too');
        self::assertStringNotContainsString('ShadButton', $slint);
        $project = (string) file_get_contents("$this->project/project.yml");
        self::assertStringContainsString("  kit: gallery\n", $project);
        self::assertStringContainsString('# my app', $project, 'comments survive');
        self::assertStringContainsString('backend: slint   # the renderer', $project);
        self::assertNull($result->gitignore);
    }

    public function testEjectedKitBuilds(): void
    {
        $this->eject('shadcn');
        $kits = KitRegistry::discover((string) realpath(self::ROOT), $this->project);
        file_put_contents("$this->project/S.rk", '<View><Button text="Hi" /><Badge text="b" /><Switch text="s" checked="{{ true }}" /></View>');
        $class = new ClassInfo('Demo\S', 'S', "$this->project/S.php", true, "$this->project/S.rk");
        $out = (new SlintCompiler($kits, 'gallery'))->compile(['Demo\S' => $class]);
        self::assertStringContainsString('GalleryButton {', $out->slint);
        self::assertStringContainsString('inherits GalleryWindow', $out->slint);
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler)) {
            self::markTestSkipped('needs the Slint toolchain');
        }
        file_put_contents("$this->project/app.slint", $out->slint);
        $command = [$compiler, "$this->project/app.slint", '-f', 'cpp', '-o', "$this->project/app.h"];
        foreach ($out->libraries as $name => $path) {
            array_push($command, '-L', "$name=$path");
        }
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
    }

    public function testMaterialKeepsFetchAndSkipsTheLibrary(): void
    {
        $result = $this->eject('material', 'brand', use: false);
        $dir = "$this->project/kits/brand";
        self::assertFileExists("$dir/rk-material.slint");
        self::assertDirectoryDoesNotExist("$dir/library");
        $yml = (string) file_get_contents("$dir/kit.yml");
        self::assertStringContainsString('fetch:', $yml);
        self::assertStringContainsString('component: BrandButton', $yml);
        self::assertStringNotContainsString('RkButton', (string) file_get_contents("$dir/rk-material.slint"));
        self::assertStringContainsString('MaterialWindow', (string) file_get_contents("$dir/rk-material.slint"), 'the library\'s own names stay');
        self::assertSame('kits/brand/library/', $result->gitignore);
        self::assertStringContainsString('kit: shadcn', (string) file_get_contents("$this->project/project.yml"), '--no-use leaves project.yml alone');
    }

    public function testProjectWithoutRagekitBlockGetsOne(): void
    {
        file_put_contents("$this->project/project.yml", "name: gallery\n");
        $this->eject('shadcn');
        self::assertStringEndsWith("ragekit:\n  kit: gallery\n", (string) file_get_contents("$this->project/project.yml"));
    }

    public function testRagekitBlockWithoutKitGetsTheLine(): void
    {
        file_put_contents("$this->project/project.yml", "name: gallery\nragekit:\n  backend: slint\n");
        $this->eject('shadcn');
        self::assertSame("name: gallery\nragekit:\n  kit: gallery\n  backend: slint\n", (string) file_get_contents("$this->project/project.yml"));
    }

    /** @return iterable<string, array{0: string, 1: ?string, 2: string}> */
    public static function errors(): iterable
    {
        yield 'unknown kit' => ['nope', null, "unknown kit 'nope' (installed: material, shadcn)"];
        yield 'bad name' => ['shadcn', 'My Kit', "'My Kit' can't be a kit name: use lowercase letters, digits and dashes, starting with a letter"];
        yield 'taken name' => ['shadcn', 'material', "a kit named 'material' is already installed; pick another name: ragekit eject shadcn <name>"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $kit, ?string $name, string $message): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage($message);
        $this->eject($kit, $name);
    }

    public function testDefaultNameMustBeUsable(): void
    {
        file_put_contents("$this->project/project.yml", "name: My Gallery\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("the project's name 'My Gallery' can't be a kit name; give one: ragekit eject shadcn <name>");
        $this->eject('shadcn');
    }

    public function testNeverOverwrites(): void
    {
        $this->eject('shadcn');
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('kits/gallery already exists; remove it or pick another name');
        $this->eject('material', 'gallery');
    }

    public function testCommandListsKitsWithoutArguments(): void
    {
        $cwd = getcwd();
        chdir($this->project);
        try {
            $tester = new CommandTester(new EjectCommand((string) realpath(self::ROOT)));
            $tester->execute([]);
            self::assertStringContainsString('material', $tester->getDisplay());
            self::assertStringContainsString('shadcn', $tester->getDisplay());
            $tester->execute(['kit' => 'shadcn']);
            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('ejected shadcn into kits/gallery (components renamed Shad… → Gallery…', $tester->getDisplay());
        } finally {
            chdir((string) $cwd);
        }
    }
}
