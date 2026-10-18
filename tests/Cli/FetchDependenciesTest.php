<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Steps\FetchDependencies;
use RageKit\Cli\Project\BackendModule;
use RageKit\Cli\Project\Project;
use RageKit\Tests\Support\InstalledToolchain;
use Symfony\Component\Console\Output\NullOutput;

final class FetchDependenciesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-fetchdeps-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return list<string> owner names */
    private function owners(string $ragekit): array
    {
        file_put_contents("$this->dir/project.yml", "name: t\nragekit:\n  backend: slint\n$ragekit");
        $project = Project::load($this->dir);
        $context = new BuildContext($project, BackendModule::find((string) realpath(self::ROOT), 'slint', InstalledToolchain::dir()), InstalledToolchain::toolchain(), new NullOutput());
        return array_values(array_unique(array_map(fn(array $entry) => $entry[0], FetchDependencies::dependencies($context))));
    }

    public function testOnlyTheActiveKitIsFetchedUpFront(): void
    {
        self::assertSame(['material'], $this->owners("  kit: material\n"));
        self::assertSame([], $this->owners("  kit: shadcn\n"), 'shadcn apps no longer download Material');
    }

    public function testModulesTheAppEnables(): void
    {
        self::assertSame(['audio'], $this->owners("  kit: shadcn\n  modules: [audio]\n"));
    }
}
