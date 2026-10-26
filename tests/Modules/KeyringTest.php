<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;

/**
 * The keyring module on Linux: tests/fixtures/keyring/probe.php drives its PHP API on the real
 * library, inside a throwaway D-Bus session with its own gnome-keyring-daemon and a temporary
 * HOME, so the user's own keyring is never touched.
 */
final class KeyringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static string $lib = '';
    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !class_exists(\FFI::class)) {
            self::markTestSkipped('Linux, with PHP\'s FFI extension');
        }
        foreach (['g++', 'dbus-run-session', 'gnome-keyring-daemon'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                self::markTestSkipped("needs $tool");
            }
        }
        $this->dir = sys_get_temp_dir() . '/rk-keyring-' . uniqid();
        mkdir("$this->dir/home", 0700, true);
        if (self::$lib === '') {
            self::$lib = $this->build();
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testStoresReadsReplacesAndDeletesSecrets(): void
    {
        $out = $this->probe(true);
        if (str_contains($out['set'] ?? '', 'error')) {
            self::markTestSkipped('the throwaway keyring refused writes: ' . $out['set']);
        }
        $secret = bin2hex("\0\t\n\\é" . str_repeat("\xff", 40));
        self::assertSame('true', $out['available']);
        self::assertSame('', $out['why']);
        self::assertSame('NULL', $out['missing']);
        self::assertSame('NULL', $out['set']);
        self::assertSame($secret, $out['get'], 'any bytes come back unchanged');
        self::assertSame('true', $out['same']);
        self::assertSame(bin2hex('second'), $out['replaced']);
        self::assertSame('NULL', $out['other-service']);
        self::assertSame('NULL', $out['gone']);
        self::assertSame(bin2hex('b'), $out['bob-kept']);
        self::assertSame('NULL', $out['delete-again'], 'deleting nothing is fine');
        self::assertFileExists("$this->dir/home/.local/share/keyrings/login.keyring", 'the throwaway keyring was used');
        self::assertSame("invalid a keyring service can't contain ':' (Windows names entries <service>:<account>)", $out['colon']);
        self::assertSame("invalid keyring names can't contain NUL bytes", $out['nul']);
        self::assertSame('error keyring names must be UTF-8', $out['not-utf8']);
        self::assertSame(bin2hex("!a keyring service can't contain ':'"), $out['native-colon']);
        self::assertSame(bin2hex("keyring names can't contain NUL bytes"), $out['native-nul']);
    }

    /**
     * A stored entry in a locked keyring whose unlock prompt doesn't succeed (here: it can't show)
     * is an error, not "nothing stored": an app would otherwise make and store a new key.
     */
    public function testALockedEntryIsNotAMissingOne(): void
    {
        if (trim((string) shell_exec('command -v gdbus')) === '') {
            self::markTestSkipped('needs gdbus');
        }
        $lock = 'gdbus call --session --dest org.freedesktop.secrets --object-path /org/freedesktop/secrets'
            . " --method org.freedesktop.Secret.Service.Lock \"['/org/freedesktop/secrets/collection/login']\" >/dev/null";
        $out = $this->run_("{$this->php('store')}; $lock; {$this->php('read')}");
        self::assertSame('NULL', $out['set']);
        self::assertSame('error the keyring is locked', $out['get']);
        self::assertSame('NULL', $out['missing'], 'nothing stored is still null');
    }

    public function testWithoutASecretServiceItSaysWhy(): void
    {
        $out = $this->probe(false);
        self::assertSame('false', $out['available']);
        self::assertStringStartsWith(bin2hex('no Secret Service: '), $out['why']);
        self::assertStringStartsWith('error ', $out['set']);
        self::assertStringStartsWith('error ', $out['get']);
    }

    /** @return array<string, string> step => its printed result */
    private function probe(bool $daemon): array
    {
        return $daemon
            ? $this->run_($this->php('all'))
            // No session bus at all: the module must say so instead of hanging.
            : $this->lines($this->env() . ' DBUS_SESSION_BUS_ADDRESS=unix:path=' . escapeshellarg("$this->dir/no-bus") . ' ' . $this->php('all'));
    }

    private function env(): string
    {
        return 'env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg("$this->dir/home") . ' XDG_RUNTIME_DIR=' . escapeshellarg($this->dir)
            . ' XDG_DATA_HOME=' . escapeshellarg("$this->dir/home/.local/share");
    }

    private function php(string $steps): string
    {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/tests/fixtures/keyring/probe.php') . ' '
            . escapeshellarg(self::$lib) . ' ' . escapeshellarg((string) realpath(self::ROOT)) . ' ' . $steps;
    }

    /** Runs $script in a throwaway D-Bus session with an unlocked gnome-keyring (temporary HOME). @return array<string, string> */
    private function run_(string $script): array
    {
        return $this->lines($this->env() . ' dbus-run-session -- sh -c '
            . escapeshellarg("printf pw | gnome-keyring-daemon --unlock --components=secrets >/dev/null; $script"));
    }

    /** @return array<string, string> step => its printed result */
    private function lines(string $command): array
    {
        exec("timeout 60 $command 2>/dev/null", $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        $out = [];
        foreach ($lines as $line) {
            $parts = explode(' ', $line, 2);
            $out[$parts[0]] = $parts[1] ?? '';
        }
        return $out;
    }

    private function build(): string
    {
        $dir = sys_get_temp_dir() . '/rk-keyringlib-' . uniqid();
        mkdir($dir);
        $root = (string) realpath(self::ROOT);
        $module = Module::load("$root/modules/keyring/module.yml", $root);
        file_put_contents("$dir/rk_module_keyring.h", ModuleEmitter::header($module));
        file_put_contents("$dir/rk_module_keyring_call.cc", ModuleEmitter::callEntry($module));
        exec("g++ -std=c++17 -O1 -Wall -Wextra -Werror -Wno-unused-function -shared -fPIC -fvisibility=hidden $root/modules/keyring/native/*.cc"
            . " $dir/rk_module_keyring_call.cc -I$dir -I$root/native/include -I$root/modules/keyring/native -ldl -o $dir/librk_module_keyring.so 2>&1", $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        return "$dir/librk_module_keyring.so";
    }
}
