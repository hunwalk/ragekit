<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Setup;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Setup\ToolchainDir;

final class ToolchainDirTest extends TestCase
{
    private string $dir;
    /** @var array<string, string> */
    private array $env;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-tcdir-' . uniqid();
        mkdir("$this->dir/repo/demo/app", 0777, true);
        $this->env = ['HOME' => "$this->dir/home"];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function pins(string $commit = 'aaaa'): Pins
    {
        $file = "$this->dir/pins-$commit/toolchain.yml";
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, "typephp:\n  git: https://example.com/typephp.git\n  commit: $commit\n");
        return Pins::load($file, 'linux-x86_64');
    }

    private function find(?Pins $pins = null): string
    {
        return ToolchainDir::find("$this->dir/repo/demo/app", $pins ?? $this->pins(), $this->env);
    }

    public function testFindsTheNearestToolchainAbove(): void
    {
        mkdir("$this->dir/repo/.ragekit");
        self::assertSame("$this->dir/repo/.ragekit", $this->find());
    }

    public function testTheProjectsOwnToolchainWins(): void
    {
        mkdir("$this->dir/repo/.ragekit");
        mkdir("$this->dir/repo/demo/app/.ragekit");
        self::assertSame("$this->dir/repo/demo/app/.ragekit", $this->find());
    }

    public function testWithoutOneItIsSharedPerUserAndPerPins(): void
    {
        $pins = $this->pins();
        self::assertSame("$this->dir/home/.local/share/ragekit/toolchains/" . $pins->fingerprint(), $this->find($pins));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $pins->fingerprint());
        self::assertSame($pins->fingerprint(), $this->pins()->fingerprint(), 'same pins, same folder');
        self::assertNotSame($pins->fingerprint(), $this->pins('bbbb')->fingerprint(), 'other pins, other folder');
    }

    public function testXdgDataHomeIsHonoured(): void
    {
        $this->env['XDG_DATA_HOME'] = "$this->dir/data";
        self::assertStringStartsWith("$this->dir/data/ragekit/toolchains/", $this->find());
    }

    public function testTheEnvironmentVariableWinsOverEverything(): void
    {
        mkdir("$this->dir/repo/.ragekit");
        $this->env['RAGEKIT_TOOLCHAIN'] = "$this->dir/mine/";
        self::assertSame("$this->dir/mine", $this->find());
    }

    public function testPerUserFolderOnEachOs(): void
    {
        self::assertSame('/home/ann/.local/share/ragekit', ToolchainDir::userDir('linux', ['HOME' => '/home/ann']));
        self::assertSame('/x/ragekit', ToolchainDir::userDir('linux', ['HOME' => '/home/ann', 'XDG_DATA_HOME' => '/x']));
        self::assertSame('/Users/ann/Library/Application Support/ragekit', ToolchainDir::userDir('macos', ['HOME' => '/Users/ann']));
        self::assertSame('C:/Users/ann/AppData/Local/ragekit', ToolchainDir::userDir('windows', ['LOCALAPPDATA' => 'C:\\Users\\ann\\AppData\\Local']));
    }

    public function testOthersAreThePerUserToolchainsOfOtherPins(): void
    {
        $pins = $this->pins();
        $parent = "$this->dir/home/.local/share/ragekit/toolchains";
        mkdir("$parent/" . $pins->fingerprint(), 0777, true);
        mkdir("$parent/0123456789ab");
        mkdir("$parent/not-a-toolchain");
        self::assertSame(["$parent/0123456789ab"], ToolchainDir::others($pins, $this->env));
    }

    public function testBuildsRecordWhenTheyUsedAToolchain(): void
    {
        mkdir("$this->dir/tc");
        self::assertNull(ToolchainDir::lastUsed("$this->dir/tc"));
        ToolchainDir::markUsed("$this->dir/tc");
        self::assertEqualsWithDelta(time(), ToolchainDir::lastUsed("$this->dir/tc"), 5);
        ToolchainDir::markUsed("$this->dir/missing");
        self::assertDirectoryDoesNotExist("$this->dir/missing");
    }

    public function testNoHomeFolderIsAnError(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('set HOME or RAGEKIT_TOOLCHAIN');
        ToolchainDir::userDir('linux', []);
    }
}
