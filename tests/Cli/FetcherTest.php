<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Fetch\Dependency;
use RageKit\Cli\Fetch\Fetcher;
use RageKit\Cli\RageKitException;

/** Third-party code kits and modules declare under `fetch:`, checked by hash or commit. */
final class FetcherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-fetch-' . uniqid();
        mkdir("$this->dir/package", 0777, true);
    }

    private function file(string $contents = "// header\n"): Dependency
    {
        file_put_contents("$this->dir/source.h", $contents);
        return Dependency::fromArray(['url' => "file://$this->dir/source.h", 'sha256' => hash('sha256', "// header\n"), 'to' => 'native/third_party/lib/source.h'], "$this->dir/package", 'module.yml');
    }

    public function testFetchesAFileOnceAndChecksItsHash(): void
    {
        $dependency = $this->file();
        self::assertTrue(Fetcher::ensure($dependency));
        self::assertSame("// header\n", file_get_contents("$this->dir/package/native/third_party/lib/source.h"));
        self::assertFalse(Fetcher::ensure($dependency), 'already there with the right hash');
        file_put_contents("$this->dir/package/native/third_party/lib/source.h", 'edited');
        self::assertTrue(Fetcher::ensure($dependency), 'a changed file is fetched again');
    }

    public function testWrongHashIsRejectedAndNothingIsWritten(): void
    {
        $dependency = $this->file("// something else\n");
        try {
            Fetcher::ensure($dependency);
            self::fail('no exception');
        } catch (RageKitException $e) {
            self::assertStringContainsString('checksum mismatch', $e->getMessage());
            self::assertStringContainsString(hash('sha256', "// something else\n"), $e->getMessage());
        }
        self::assertFileDoesNotExist("$this->dir/package/native/third_party/lib/source.h");
    }

    public function testFetchesAFolderFromAGitTagAtAPinnedCommit(): void
    {
        $repo = "$this->dir/repo";
        mkdir("$repo/ui/src", 0777, true);
        file_put_contents("$repo/ui/src/lib.slint", 'export component X {}');
        file_put_contents("$repo/README", 'other files stay out');
        $git = 'git -C ' . escapeshellarg($repo) . ' -c user.email=t@t -c user.name=t';
        exec("git init -q " . escapeshellarg($repo) . " && $git add -A && $git commit -q -m one && $git tag v1", $out, $code);
        self::assertSame(0, $code);
        $commit = trim((string) shell_exec("$git rev-parse HEAD"));

        $dependency = Dependency::fromArray(['git' => $repo, 'ref' => 'v1', 'commit' => $commit, 'path' => 'ui/src', 'to' => 'library'], "$this->dir/package", 'kit.yml');
        self::assertTrue(Fetcher::ensure($dependency));
        self::assertSame('export component X {}', file_get_contents("$this->dir/package/library/lib.slint"));
        self::assertFileDoesNotExist("$this->dir/package/library/README");
        self::assertFalse(Fetcher::ensure($dependency));

        $wrong = Dependency::fromArray(['git' => $repo, 'ref' => 'v1', 'commit' => str_repeat('0', 40), 'path' => 'ui/src', 'to' => 'other'], "$this->dir/package", 'kit.yml');
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("v1 is commit $commit, expected " . str_repeat('0', 40));
        Fetcher::ensure($wrong);
    }

    public function testDeclarationsAreValidated(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('kit.yml: fetch[0]: needs url + sha256, or git + ref + commit, and to');
        Dependency::fromArray(['url' => 'https://x'], "$this->dir/package", 'kit.yml', 0);
    }

    public function testTargetMustStayInsideThePackage(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("kit.yml: fetch[0]: to '../x' must be a path inside the package");
        Dependency::fromArray(['url' => 'https://x', 'sha256' => 'a', 'to' => '../x'], "$this->dir/package", 'kit.yml', 0);
    }
}
