<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\HostPhp;

final class HostPhpTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-hostphp-' . uniqid();
        mkdir("$this->dir/a", 0777, true);
        mkdir("$this->dir/b");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function library(array $env = []): ?string
    {
        return HostPhp::library($env, ["$this->dir/a", "$this->dir/b"]);
    }

    public function testNoLibraryMeansNone(): void
    {
        self::assertNull($this->library());
    }

    public function testFindsDebiansVersionedLibrary(): void
    {
        $name = 'libphp' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.so';
        touch("$this->dir/b/$name");
        self::assertSame("$this->dir/b/$name", $this->library());
    }

    public function testThePlainNameWinsInTheSameFolder(): void
    {
        touch("$this->dir/a/libphp" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.so');
        touch("$this->dir/a/libphp.so");
        self::assertSame("$this->dir/a/libphp.so", $this->library());
    }

    public function testOtherPhpVersionsDontCount(): void
    {
        touch("$this->dir/a/libphp7.4.so");
        self::assertNull($this->library());
    }

    public function testTheEnvironmentVariableOverrides(): void
    {
        touch("$this->dir/a/libphp.so");
        touch("$this->dir/mine.so");
        self::assertSame("$this->dir/mine.so", $this->library(['RAGEKIT_LIBPHP' => "$this->dir/mine.so"]));
        self::assertNull($this->library(['RAGEKIT_LIBPHP' => 'none']));
    }

    public function testAMissingOverrideIsAnError(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('RAGEKIT_LIBPHP');
        $this->library(['RAGEKIT_LIBPHP' => "$this->dir/missing.so"]);
    }

    public function testPhpHomeLeavesItToTpc(): void
    {
        self::assertNull(HostPhp::find(['PHP_HOME' => '/opt/php', 'RAGEKIT_LIBPHP' => "$this->dir/missing.so"]));
    }

    public function testPhpxIsBuiltOnceForEachPhp(): void
    {
        if (trim((string) shell_exec('command -v cmake')) === '' || trim((string) shell_exec('command -v php-config')) === '') {
            self::markTestSkipped('needs cmake and php-config');
        }
        touch("$this->dir/libphp-a.so");
        touch("$this->dir/libphp-b.so");
        mkdir("$this->dir/phpx/lib", 0777, true);
        $commands = [];
        $run = function (array $command) use (&$commands): int {
            $commands[] = $command;
            touch("$this->dir/phpx/lib/libphpx.so");
            return 0;
        };

        $host = HostPhp::find(['RAGEKIT_LIBPHP' => "$this->dir/libphp-a.so"]);
        $home = $host->home("$this->dir/toolchain");
        self::assertSame("$this->dir/libphp-a.so", readlink("$home/lib/libphp.so"));
        self::assertSame(PHP_BINARY, readlink("$home/bin/php"));
        self::assertTrue($host->buildPhpx("$this->dir/phpx", $home, $run));
        self::assertSame('cmake', $commands[0][0]);
        self::assertContains("-Dphp_dir=$home", $commands[0]);
        self::assertFalse($host->buildPhpx("$this->dir/phpx", $home, $run), 'same PHP: built already');

        $other = HostPhp::find(['RAGEKIT_LIBPHP' => "$this->dir/libphp-b.so"]);
        self::assertSame("$this->dir/libphp-b.so", readlink($other->home("$this->dir/toolchain") . '/lib/libphp.so'));
        self::assertTrue($other->buildPhpx("$this->dir/phpx", $home, $run), 'another libphp: built again');
    }

    public function testAFailedPhpxBuildSaysHowToSkipIt(): void
    {
        if (trim((string) shell_exec('command -v cmake')) === '' || trim((string) shell_exec('command -v php-config')) === '') {
            self::markTestSkipped('needs cmake and php-config');
        }
        touch("$this->dir/libphp.so");
        $host = HostPhp::find(['RAGEKIT_LIBPHP' => "$this->dir/libphp.so"]);
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('RAGEKIT_LIBPHP=none');
        $host->buildPhpx("$this->dir/phpx", $host->home("$this->dir/toolchain"), fn() => 1);
    }
}
