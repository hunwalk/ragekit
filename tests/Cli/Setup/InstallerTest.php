<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Setup;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Installer;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Toolchain\Process;

final class InstallerTest extends TestCase
{
    private string $dir;
    private string $commit;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-setup-' . uniqid();
        mkdir("$this->dir/upstream", 0777, true);
        mkdir("$this->dir/pins/tool/patches", 0777, true);
        mkdir("$this->dir/archive/pkg/lib", 0777, true);

        // An "upstream" repository with an ignored vendor/ file, like TypePHP after composer install.
        $git = fn(string ...$args) => exec('git -C ' . escapeshellarg("$this->dir/upstream") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
        file_put_contents("$this->dir/upstream/tool.php", "<?php echo 'v1';\n");
        file_put_contents("$this->dir/upstream/.gitignore", "vendor/\n");
        $git('init', '-q');
        $git('config', 'uploadpack.allowAnySHA1InWant', 'true');
        $git('-c', 'user.name=t', '-c', 'user.email=t@t', 'add', '.');
        $git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'v1');
        $this->commit = trim((string) $git('rev-parse', 'HEAD'));

        file_put_contents("$this->dir/pins/tool/patches/fix.patch", "--- a/tool.php\n+++ b/tool.php\n@@ -1 +1 @@\n-<?php echo 'v1';\n+<?php echo 'v1 patched';\n");

        // A release archive with one leading folder (like Slint's).
        file_put_contents("$this->dir/archive/pkg/lib/libthing.so", 'binary');
        exec('tar -czf ' . escapeshellarg("$this->dir/thing.tar.gz") . ' -C ' . escapeshellarg("$this->dir/archive") . ' pkg');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function pins(string $yaml): Pins
    {
        file_put_contents("$this->dir/pins/toolchain.yml", $yaml);
        return Pins::load("$this->dir/pins/toolchain.yml", 'linux-x86_64');
    }

    private function toolPins(string $extra = ''): Pins
    {
        return $this->pins("tool:\n  git: $this->dir/upstream\n  commit: $this->commit\n  patches: [tool/patches/fix.patch]\n$extra");
    }

    private function archivePins(string $sha): Pins
    {
        return $this->pins("thing:\n  version: 1.0\n  platforms:\n    linux-x86_64:\n      downloads:\n        - url: $this->dir/thing.tar.gz\n          sha256: $sha\n          strip: 1\n");
    }

    /** @param array<string, string> $env added to the environment */
    private function install(Pins $pins, string $name, array $env = []): bool
    {
        return Installer::install($pins->component($name), "$this->dir/install/$name", new Process($env + getenv()), fn(string $line) => null);
    }

    private function useWindowsLineEndings(string $file): void
    {
        file_put_contents($file, str_replace("\n", "\r\n", (string) file_get_contents($file)));
    }

    public function testAPatchWithWindowsLineEndingsStillFits(): void
    {
        // Git for Windows checks RageKit's patch files out with CRLF (core.autocrlf), while the
        // files they patch come from Composer's zips with LF.
        $this->useWindowsLineEndings("$this->dir/pins/tool/patches/fix.patch");
        self::assertTrue($this->install($this->toolPins(), 'tool'));
        self::assertSame("<?php echo 'v1 patched';\n", file_get_contents("$this->dir/install/tool/tool.php"));
    }

    public function testTheCheckoutKeepsUpstreamLineEndingsWhateverTheUsersGitConverts(): void
    {
        $config = "$this->dir/gitconfig";
        file_put_contents($config, "[core]\n\tautocrlf = true\n");
        self::assertTrue($this->install($this->toolPins(), 'tool', ['GIT_CONFIG_GLOBAL' => $config]));
        self::assertSame("<?php echo 'v1 patched';\n", file_get_contents("$this->dir/install/tool/tool.php"));
    }

    public function testLineEndingsDontChangeTheFingerprint(): void
    {
        $lf = $this->toolPins()->component('tool')->fingerprint();
        $this->useWindowsLineEndings("$this->dir/pins/tool/patches/fix.patch");
        self::assertSame($lf, $this->toolPins()->component('tool')->fingerprint());
    }

    public function testChecksOutTheCommitAndAppliesThePatches(): void
    {
        self::assertTrue($this->install($this->toolPins(), 'tool'));
        self::assertSame("<?php echo 'v1 patched';\n", file_get_contents("$this->dir/install/tool/tool.php"));
        self::assertTrue(Installer::installed($this->toolPins()->component('tool'), "$this->dir/install/tool"));
    }

    public function testASecondRunKeepsAnInstallThatMatchesThePins(): void
    {
        $this->install($this->toolPins(), 'tool');
        self::assertFalse($this->install($this->toolPins(), 'tool'));
    }

    public function testChangedPatchMeansTheInstallIsOutdated(): void
    {
        $this->install($this->toolPins(), 'tool');
        file_put_contents("$this->dir/pins/tool/patches/fix.patch", str_replace('patched', 'patched again', (string) file_get_contents("$this->dir/pins/tool/patches/fix.patch")));
        self::assertFalse(Installer::installed($this->toolPins()->component('tool'), "$this->dir/install/tool"));
        self::assertTrue($this->install($this->toolPins(), 'tool'));
        self::assertSame("<?php echo 'v1 patched again';\n", file_get_contents("$this->dir/install/tool/tool.php"));
    }

    public function testAPatchThatDoesNotFitFailsWithoutMarkingTheInstall(): void
    {
        file_put_contents("$this->dir/pins/tool/patches/fix.patch", "--- a/tool.php\n+++ b/tool.php\n@@ -1 +1 @@\n-<?php echo 'v0';\n+<?php echo 'x';\n");
        try {
            $this->install($this->toolPins(), 'tool');
            self::fail('expected a RageKitException');
        } catch (RageKitException $e) {
            self::assertStringContainsString('fix.patch', $e->getMessage());
        }
        self::assertFalse(Installer::installed($this->toolPins()->component('tool'), "$this->dir/install/tool"));
    }

    public function testDownloadsAreCheckedAndExtracted(): void
    {
        $sha = hash_file('sha256', "$this->dir/thing.tar.gz");
        self::assertTrue($this->install($this->archivePins($sha), 'thing'));
        self::assertSame('binary', file_get_contents("$this->dir/install/thing/lib/libthing.so"));
        self::assertFileDoesNotExist("$this->dir/install/thing/thing.tar.gz");
    }

    public function testAChecksumMismatchFails(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('checksum');
        $this->install($this->archivePins(str_repeat('0', 64)), 'thing');
    }

    public function testPlatformListsAreAppended(): void
    {
        $pins = $this->toolPins("  platforms:\n    linux-x86_64:\n      patches: [tool/patches/linux.patch]\n    windows-x86_64:\n      patches: [tool/patches/windows.patch]\n");
        $patches = array_map('basename', $pins->component('tool')->patches);
        self::assertSame(['fix.patch', 'linux.patch'], $patches);
    }

    public function testAComponentWithNothingForThisPlatformIsUnavailable(): void
    {
        $pins = $this->pins("thing:\n  version: 1.0\n  platforms:\n    windows-x86_64:\n      downloads: []\n");
        self::assertFalse($pins->component('thing')->available());
    }

    public function testNamesTheToolsAnInstallNeeds(): void
    {
        $pins = $this->pins("tool:\n  git: $this->dir/upstream\n  commit: $this->commit\n  composer-lock: tool/composer.lock\n  downloads:\n    - url: https://example.invalid/a.tar.gz\n      sha256: '00'\n    - url: https://example.invalid/b.exe\n      sha256: '00'\n");
        self::assertSame(['git', 'composer', 'tar', '7z'], Installer::tools($pins->component('tool')));
    }
}
