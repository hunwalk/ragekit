<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\BackendModule;
use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Steps\ApplyOptions;
use RageKit\Cli\Build\Steps\CopyAssets;
use RageKit\Cli\Build\Steps\PrepareModules;
use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;
use Symfony\Component\Console\Output\NullOutput;
use RageKit\Tests\Support\InstalledToolchain;

final class PrepareModulesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function context(string $ragekit, string $backend = 'slint'): BuildContext
    {
        $dir = sys_get_temp_dir() . '/rk-prep-' . uniqid();
        mkdir($dir);
        return $this->contextIn($dir, $ragekit, $backend);
    }

    private function contextIn(string $dir, string $ragekit, string $backend = 'slint'): BuildContext
    {
        file_put_contents("$dir/project.yml", "name: t\nragekit:\n  backend: $backend\n$ragekit");
        $project = Project::load($dir);
        return new BuildContext($project, BackendModule::find((string) realpath(self::ROOT), $project->backend, InstalledToolchain::dir()), InstalledToolchain::toolchain(), new NullOutput());
    }

    public function testGeneratesGlueAndRegistersTheLibrary(): void
    {
        $context = $this->context("  modules: [audio]\n");
        self::assertSame('audio', (new PrepareModules())->run($context));
        $gen = $context->generatedDir() . '/modules/audio';
        foreach (['host/audio.stub.php', 'host/audio_host.cc', 'lib/rk_module_audio.h', 'lib/rk_module_audio_call.cc'] as $file) {
            self::assertFileExists("$gen/$file");
        }
        $root = (string) realpath(self::ROOT);
        self::assertContains("$root/modules/audio/src", $context->config['sources']);
        self::assertContains("$gen/host", $context->config['sources']);
        self::assertNotContains("$gen/lib", $context->config['sources'], 'the call entry belongs to the library, not the exe');
        self::assertContains("$gen/lib", $context->config['include-paths']);
        $lib = $context->moduleLibraries[0];
        self::assertSame('rk_module_audio', $lib['name']);
        self::assertContains("$gen/lib/rk_module_audio_call.cc", $lib['sources']);
        self::assertContains("$root/modules/audio/native", $lib['sources']);
        self::assertContains("$root/modules/audio/native/third_party/miniaudio", $lib['include-paths']);
    }

    public function testNoModules(): void
    {
        $context = $this->context('');
        self::assertSame('none', (new PrepareModules())->run($context));
        self::assertSame([], $context->moduleLibraries);
    }

    public function testUnknownModule(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("unknown module 'gps' (installed: audio, crypto, dialogs, http, keyring, notifications, tray)");
        (new PrepareModules())->run($this->context("  modules: [gps]\n"));
    }

    public function testModulesMustBeAList(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('modules must be a list, e.g. modules: [audio]');
        (new PrepareModules())->run($this->context("  modules: audio\n"));
    }

    public function testDuplicateModulesCountOnce(): void
    {
        $context = $this->context("  modules: [audio, audio]\n");
        self::assertSame('audio', (new PrepareModules())->run($context));
        self::assertCount(1, $context->moduleLibraries);
    }

    public function testAssetsMustBeAList(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('assets must be a list, e.g. assets: [assets/]');
        (new CopyAssets())->run($this->context("  assets: assets/\n"));
    }

    public function testModulesNeedTheSlintBackend(): void
    {
        // A backend without runtime module loading (no `dev:` section), in a stand-in RageKit root.
        $root = sys_get_temp_dir() . '/rk-plainroot-' . uniqid();
        mkdir("$root/src/Plain", 0777, true);
        file_put_contents("$root/src/Plain/backend.yml", "sources: []\n");
        $dir = sys_get_temp_dir() . '/rk-prep-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/project.yml", "name: t\nragekit:\n  backend: plain\n  modules: [audio]\n");
        $project = Project::load($dir);
        $context = new BuildContext($project, BackendModule::find($root, 'plain', InstalledToolchain::dir()), InstalledToolchain::toolchain(), new NullOutput());
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('modules need the slint backend (this app uses plain)');
        (new PrepareModules())->run($context);
    }

    public function testModulesAndAssetsAreCoreOptions(): void
    {
        $context = $this->context("  modules: [audio]\n  assets: [assets/]\n");
        $summary = (new ApplyOptions())->run($context);
        self::assertStringNotContainsString('ignored', $summary);
    }

    public function testLinksBecomeADefine(): void
    {
        $context = $this->context("  links: [devnull, My.App+x]\n");
        self::assertStringContainsString('links: devnull, my.app+x', (new ApplyOptions())->run($context));
        self::assertContains('RAGEKIT_LINKS=devnull__my.app+x', $context->config['defines']);
    }

    /** @return iterable<array{0: string, 1: string}> */
    public static function badLinks(): iterable
    {
        yield ['  links: devnull', 'links must be a list of URL schemes'];
        yield ['  links: [1app]', "links: '1app' isn't a URL scheme"];
        yield ['  links: [my_app]', "links: 'my_app' isn't a URL scheme"];
        yield ['  links: [https]', "links: 'https' belongs to the browser"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLinks')]
    public function testBadLinks(string $yaml, string $message): void
    {
        $context = $this->context("$yaml\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage($message);
        (new ApplyOptions())->run($context);
    }

    public function testCopyAssets(): void
    {
        $context = $this->context("  assets: [assets/, readme.txt]\n");
        $dir = $context->project->dir;
        mkdir("$dir/assets/sub", 0777, true);
        file_put_contents("$dir/assets/a.wav", 'A');
        file_put_contents("$dir/assets/sub/my tone é.wav", 'B');
        file_put_contents("$dir/readme.txt", 'R');
        self::assertSame('3 files', (new CopyAssets())->run($context));
        $out = $context->buildDir();
        self::assertSame('B', file_get_contents("$out/assets/sub/my tone é.wav"));
        self::assertSame('R', file_get_contents("$out/readme.txt"));
        touch("$out/assets/a.wav", 1000000000);
        (new CopyAssets())->run($context);
        self::assertSame(1000000000, filemtime("$out/assets/a.wav"), 'unchanged files are not rewritten');
    }

    /** @return list<array{string}> */
    public static function outsidePaths(): array
    {
        return [['../secret.txt'], ['assets/../../secret.txt'], ['/etc/hostname'], ['C:/Windows/win.ini'], ['\\\\server\\share']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outsidePaths')]
    public function testAssetsMustStayInsideTheProject(string $path): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("asset paths must be inside the project folder: $path");
        (new CopyAssets())->run($this->context('  assets: [' . json_encode($path) . "]\n"));
    }

    public function testAssetPathsAreNormalised(): void
    {
        $context = $this->context("  assets: [./assets//a.wav]\n");
        mkdir($context->project->dir . '/assets');
        file_put_contents($context->project->dir . '/assets/a.wav', 'A');
        self::assertSame('1 file', (new CopyAssets())->run($context));
        self::assertSame('A', file_get_contents($context->buildDir() . '/assets/a.wav'));
    }

    public function testRemovedAssetsLeaveTheBuildFolder(): void
    {
        $context = $this->context("  assets: [assets/, readme.txt]\n");
        $dir = $context->project->dir;
        mkdir("$dir/assets/sub", 0777, true);
        file_put_contents("$dir/assets/a.wav", 'A');
        file_put_contents("$dir/assets/sub/b.wav", 'B');
        file_put_contents("$dir/readme.txt", 'R');
        (new CopyAssets())->run($context);
        $out = $context->buildDir();
        file_put_contents("$out/mine.txt", 'not an asset');

        unlink("$dir/assets/sub/b.wav");
        rmdir("$dir/assets/sub");
        self::assertSame('2 files, 1 removed', (new CopyAssets())->run($context));
        self::assertFileDoesNotExist("$out/assets/sub/b.wav");
        self::assertDirectoryDoesNotExist("$out/assets/sub", 'emptied folders go too');
        self::assertFileExists("$out/assets/a.wav");

        $fewer = $this->contextIn($dir, "  assets: [assets/]\n");
        self::assertSame('1 file, 1 removed', (new CopyAssets())->run($fewer));
        self::assertFileDoesNotExist("$out/readme.txt");
        self::assertFileExists("$out/mine.txt", 'files ragekit did not copy are left alone');

        $none = $this->contextIn($dir, '');
        self::assertSame('none, 1 removed', (new CopyAssets())->run($none));
        self::assertDirectoryDoesNotExist("$out/assets");
    }

    public function testMissingAsset(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('asset not found: nope.wav');
        (new CopyAssets())->run($this->context("  assets: [nope.wav]\n"));
    }
}
