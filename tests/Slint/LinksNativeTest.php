<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;

/**
 * The bridge's link code on its own (native/slint/bridge/rk_links.cc with tests/fixtures/links/
 * probe_links.cc): .desktop quoting, symlinked mimeapps.list, where the socket may live. The whole
 * flow in a running app: tests/e2e/links.sh.
 */
final class LinksNativeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('Linux with g++');
        }
        $this->dir = sys_get_temp_dir() . '/rk-linksnative-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('chmod -R u+rwx ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** Builds the probe into $folder (its name is part of the paths under test). */
    private function probe(string $folder): string
    {
        mkdir($folder, 0777, true);
        $root = (string) realpath(self::ROOT);
        exec('g++ -std=c++20 -O1 -Wall -Wextra -Werror ' . escapeshellarg("$root/native/slint/bridge/rk_links.cc") . ' '
            . escapeshellarg("$root/tests/fixtures/links/probe_links.cc") . " -I$root/native/slint/bridge -lpthread -o "
            . escapeshellarg("$folder/probe") . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        return "$folder/probe";
    }

    /** @param array<string, string> $env */
    private function run_(string $probe, string $args, array $env): string
    {
        $vars = '';
        foreach ($env as $name => $value) {
            $vars .= ' ' . $name . '=' . escapeshellarg($value);
        }
        return (string) shell_exec('env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg("$this->dir/home") . $vars . ' ' . escapeshellarg($probe) . " $args 2>&1");
    }

    public function testExecQuotingAndSymlinkedMimeapps(): void
    {
        $probe = $this->probe("$this->dir/odd \$dir \"q\" 100%");
        mkdir("$this->dir/dotfiles");
        mkdir("$this->dir/config");
        file_put_contents("$this->dir/dotfiles/mimeapps.list", "[Default Applications]\ntext/html=firefox.desktop\n");
        chmod("$this->dir/dotfiles/mimeapps.list", 0640);
        symlink("$this->dir/dotfiles/mimeapps.list", "$this->dir/config/mimeapps.list");
        $out = $this->run_($probe, 'register rktest', ['XDG_DATA_HOME' => "$this->dir/data", 'XDG_CONFIG_HOME' => "$this->dir/config"]);
        self::assertSame("register []\n", $out);
        $desktop = (string) file_get_contents("$this->dir/data/applications/ragekit-probe.desktop");
        // Exec rules (\" \$ for the quoted path, %% for %), then string escaping (\ becomes \\).
        self::assertStringContainsString('Exec="' . "$this->dir/odd " . '\\\\$dir \\\\"q\\\\" 100%%/probe" %u' . "\n", $desktop);
        self::assertTrue(is_link("$this->dir/config/mimeapps.list"), 'the symlink is kept');
        self::assertSame("[Default Applications]\ntext/html=firefox.desktop\nx-scheme-handler/rktest=ragekit-probe.desktop\n", file_get_contents("$this->dir/dotfiles/mimeapps.list"));
        self::assertSame('0640', substr(sprintf('%o', fileperms("$this->dir/dotfiles/mimeapps.list")), -4), 'its permissions too');
    }

    public function testSocketOnlyInAFolderPrivateToTheUser(): void
    {
        $probe = $this->probe("$this->dir/bin");
        mkdir("$this->dir/runtime", 0700);
        $out = $this->run_($probe, "claim rktest 'rktest://x'", ['XDG_RUNTIME_DIR' => "$this->dir/runtime"]);
        self::assertSame("event @links open rktest://x\nclaim 1\n", $out, "the first instance opens the link it was started with");
        self::assertFileDoesNotExist("$this->dir/runtime/ragekit-probe.sock", 'removed on a clean stop');

        // A runtime folder others can write to isn't used; the private /tmp/ragekit-<uid> is.
        mkdir("$this->dir/open", 0777);
        chmod("$this->dir/open", 0777);
        $this->run_($probe, 'claim rktest', ['XDG_RUNTIME_DIR' => "$this->dir/open"]);
        self::assertFileDoesNotExist("$this->dir/open/ragekit-probe.lock");
        $private = '/tmp/ragekit-' . getmyuid();
        self::assertDirectoryExists($private);
        self::assertSame('0700', substr(sprintf('%o', fileperms($private)), -4));
        self::assertFileExists("$private/ragekit-probe.lock");
        unlink("$private/ragekit-probe.lock");
    }
}
