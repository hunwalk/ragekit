<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Toolchain\Process;

final class ProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-process-' . uniqid();
        mkdir("$this->dir/a", 0777, true);
        mkdir("$this->dir/b");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function find(string $program): ?string
    {
        return Process::findOnWindowsPath($program, "$this->dir/a;;$this->dir/b", '.COM;.EXE;.BAT;.CMD', '/');
    }

    public function testFindsABatchFileNotTheShellScriptNextToIt(): void
    {
        // Composer's Windows installer puts composer.bat next to a `composer` script for Git Bash.
        touch("$this->dir/b/composer");
        touch("$this->dir/b/composer.bat");

        self::assertSame("$this->dir/b/composer.bat", $this->find('composer'));
    }

    public function testEarlierPathEntriesWinAndPathextOrderDecidesWithinOne(): void
    {
        touch("$this->dir/a/tool.cmd");
        touch("$this->dir/a/tool.exe");
        touch("$this->dir/b/tool.exe");

        self::assertSame("$this->dir/a/tool.exe", $this->find('tool'));
    }

    public function testANameWithAnExtensionIsLookedUpAsIs(): void
    {
        touch("$this->dir/b/cl.exe");

        self::assertSame("$this->dir/b/cl.exe", $this->find('cl.exe'));
    }

    public function testUnknownProgramIsNull(): void
    {
        self::assertNull($this->find('nothing'));
    }
}
