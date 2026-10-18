<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Build\NativeBuild;

final class NativeBuildTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-lib-' . uniqid();
        mkdir("$this->dir/out", 0777, true); // outputs never live in a source folder
        file_put_contents("$this->dir/a.cc", 'int a;');
        file_put_contents("$this->dir/out/libx.so", 'bin');
        file_put_contents("$this->dir/out/libx.so.cmd", NativeBuild::stamp(['g++', '-O2'], ["$this->dir/a.cc"]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...glob("$this->dir/out/*") ?: [], ...array_filter(glob("$this->dir/*") ?: [], 'is_file')]);
        rmdir("$this->dir/out");
        rmdir($this->dir);
    }

    private function upToDate(array $command = ['g++', '-O2']): bool
    {
        return NativeBuild::upToDate("$this->dir/out/libx.so", NativeBuild::stamp($command, ["$this->dir/a.cc"]));
    }

    public function testUpToDateWhenNothingChanged(): void
    {
        self::assertTrue($this->upToDate());
    }

    public function testMissingOutputMeansRebuild(): void
    {
        unlink("$this->dir/out/libx.so");
        self::assertFalse($this->upToDate());
    }

    public function testNewHeaderNextToASourceMeansRebuild(): void
    {
        file_put_contents("$this->dir/app.h", '#pragma once');
        self::assertFalse($this->upToDate());
    }

    public function testEditKeepingTheModificationTimeMeansRebuild(): void
    {
        $mtime = filemtime("$this->dir/a.cc");
        file_put_contents("$this->dir/a.cc", 'int b;'); // same second, same size: only the content tells
        touch("$this->dir/a.cc", $mtime);
        self::assertFalse($this->upToDate());
    }

    public function testPhpFilesAreIgnored(): void
    {
        file_put_contents("$this->dir/Screens.php", '<?php');
        self::assertTrue($this->upToDate());
    }

    public function testChangedCommandMeansRebuild(): void
    {
        self::assertFalse($this->upToDate(['g++', '-O3']));
    }

    public function testMissingStampMeansRebuild(): void
    {
        unlink("$this->dir/out/libx.so.cmd");
        self::assertFalse($this->upToDate());
    }
}
