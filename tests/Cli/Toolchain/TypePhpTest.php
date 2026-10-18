<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Installer;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Toolchain\TypePhp;

final class TypePhpTest extends TestCase
{
    private string $dir;
    private string|false $typePhpHome;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-typephp-' . uniqid();
        mkdir("$this->dir/pins", 0777, true);
        file_put_contents("$this->dir/pins/toolchain.yml", "typephp:\n  git: https://example.invalid/typephp.git\n  commit: abc123\n");
        $this->typePhpHome = getenv('TYPEPHP_HOME');
        putenv('TYPEPHP_HOME');
    }

    protected function tearDown(): void
    {
        putenv($this->typePhpHome === false ? 'TYPEPHP_HOME' : "TYPEPHP_HOME=$this->typePhpHome");
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function pins(): Pins
    {
        return Pins::load("$this->dir/pins/toolchain.yml");
    }

    private function fakeInstall(string $marker): void
    {
        mkdir("$this->dir/.ragekit/typephp/bin", 0777, true);
        mkdir("$this->dir/.ragekit/typephp/vendor");
        touch("$this->dir/.ragekit/typephp/bin/tpc.php");
        file_put_contents("$this->dir/.ragekit/typephp/" . Installer::MARKER, $marker);
    }

    public function testUsesTheInstalledCheckout(): void
    {
        $this->fakeInstall($this->pins()->component('typephp')->fingerprint() . "\n");
        $typePhp = TypePhp::locate("$this->dir/.ragekit", $this->pins());
        self::assertSame([PHP_BINARY, "$this->dir/.ragekit/typephp/bin/tpc.php"], $typePhp->command);
    }

    public function testNotSetUpSaysToRunSetup(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('ragekit setup');
        TypePhp::locate("$this->dir/.ragekit", $this->pins());
    }

    public function testAnInstallForOtherPinsSaysToRunSetupAgain(): void
    {
        $this->fakeInstall("something else\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('ragekit setup');
        TypePhp::locate("$this->dir/.ragekit", $this->pins());
    }

    public function testTypePhpHomeWins(): void
    {
        mkdir("$this->dir/own/bin", 0777, true);
        mkdir("$this->dir/own/vendor");
        touch("$this->dir/own/bin/tpc.php");
        putenv("TYPEPHP_HOME=$this->dir/own");
        self::assertSame([PHP_BINARY, "$this->dir/own/bin/tpc.php"], TypePhp::locate("$this->dir/.ragekit", $this->pins())->command);
    }
}
