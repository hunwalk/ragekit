<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Setup;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Setup\Check;
use RageKit\Cli\Setup\Doctor;
use RageKit\Cli\Setup\Installer;
use RageKit\Cli\Setup\Pins;

final class DoctorTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-doctor-' . uniqid();
        mkdir("$this->dir/pins", 0777, true);
        file_put_contents("$this->dir/pins/toolchain.yml", "typephp:\n  git: https://example.invalid/typephp.git\n  commit: abc123\nslint:\n  version: 1.18.1\n  platforms:\n    linux-x86_64:\n      downloads:\n        - url: https://example.invalid/slint.tar.gz\n          sha256: '00'\n");
        foreach (['TYPEPHP_HOME', 'SLINT_HOME'] as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, Check> */
    private function checks(): array
    {
        $checks = [];
        foreach ((new Doctor(Pins::load("$this->dir/pins/toolchain.yml", 'linux-x86_64'), "$this->dir/.ragekit", $this->dir))->checks() as $check) {
            $checks[$check->name] = $check;
        }
        return $checks;
    }

    public function testMissingToolchainFailsWithTheFix(): void
    {
        $checks = $this->checks();
        self::assertSame(Check::FAIL, $checks['TypePHP']->status);
        self::assertStringContainsString('ragekit setup', $checks['TypePHP']->detail);
        self::assertSame(Check::FAIL, $checks['Slint']->status);
    }

    public function testAnInstallMatchingThePinsIsFine(): void
    {
        $pins = Pins::load("$this->dir/pins/toolchain.yml", 'linux-x86_64');
        foreach (['typephp', 'slint'] as $name) {
            mkdir("$this->dir/.ragekit/$name", 0777, true);
            file_put_contents("$this->dir/.ragekit/$name/" . Installer::MARKER, $pins->component($name)->fingerprint() . "\n");
        }
        $checks = $this->checks();
        self::assertSame(Check::OK, $checks['TypePHP']->status);
        self::assertSame(Check::OK, $checks['Slint']->status);
        self::assertStringContainsString('1.18.1', $checks['Slint']->detail);
    }

    public function testAnOutdatedInstallSaysToSetUpAgain(): void
    {
        mkdir("$this->dir/.ragekit/typephp", 0777, true);
        file_put_contents("$this->dir/.ragekit/typephp/" . Installer::MARKER, "old\n");
        self::assertStringContainsString('again', $this->checks()['TypePHP']->detail);
    }

    public function testAnOverrideIsReportedAsSuch(): void
    {
        putenv("SLINT_HOME=$this->dir/own-slint");
        $check = $this->checks()['Slint'];
        self::assertSame(Check::FAIL, $check->status); // no slint-compiler there
        self::assertStringContainsString('SLINT_HOME', $check->detail);
    }
}
