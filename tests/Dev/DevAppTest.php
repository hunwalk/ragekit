<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Dev\Bootstrap;
use RageKit\Slint\Dev\Protocol;

require_once __DIR__ . '/../../src/Slint/Dev/Protocol.php';

/**
 * The dev Screens.php + functions.php with the real SlintBackend, in a subprocess (they declare
 * the same functions/class as the runtime tests' fakes). This test plays the shell: PHP connects
 * to its loopback address and sends the token first.
 */
final class DevAppTest extends TestCase
{
    public function testRealBackendOverTheProtocol(): void
    {
        $dir = sys_get_temp_dir() . '/rk-devapp-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/screens.json", json_encode(['windows' => [[
            'name' => 'rk-screens', 'window' => null, 'frameless' => false,
            'values' => [['id' => 3, 'owner' => 'store', 'source' => 'property', 'name' => 'session.userName', 'type' => 'string']],
            'actions' => [['id' => 3, 'owner' => 'store', 'kind' => 'call', 'target' => 'session.signIn', 'args' => [['expr', 0]], 'params' => ['string']]],
            'pages' => [[
                'class' => 'App\\Counter', 'name' => 'Counter', 'page' => 0,
                'values' => [['id' => 1, 'owner' => 'screen', 'source' => 'property', 'name' => 'count', 'type' => 'int'], ['id' => 2, 'owner' => 'screen', 'source' => 'property', 'name' => 'name', 'type' => 'string']],
                'actions' => [['id' => 1, 'owner' => 'screen', 'kind' => 'call', 'target' => 'add', 'args' => [], 'params' => []], ['id' => 2, 'owner' => 'screen', 'kind' => 'set', 'target' => 'name', 'binding' => 2, 'type' => 'string']],
            ]],
        ]]]));
        file_put_contents("$dir/Counter.php", "<?php\nnamespace App;\nfinal class Counter extends \\RageKit\\Screen { public int \$count = 0; public string \$name = ''; public function add(): void { \$this->count++; } }\n");
        file_put_contents("$dir/Stores.php", "<?php\nnamespace App;\n"
            . "final class Session extends \\RageKit\\Store { public string \$userName = ''; public function signIn(string \$n): void { \$this->userName = \$n; } }\n"
            . "final class AppStore extends \\RageKit\\RootStore { public Session \$session; public function __construct() { parent::__construct(); \$this->session = new Session(); } }\n"
            . "final class CounterApp extends \\RageKit\\App {\n"
            . "    protected function firstScreen(): \\RageKit\\Screen { return new Counter(); }\n"
            . "    protected function createStore(): \\RageKit\\RootStore { return new AppStore(); }\n"
            . "    protected function title(): string { return 'C'; }\n"
            . "    protected function width(): int { return 300; }\n"
            . "    protected function height(): int { return 200; }\n"
            . "}\n");
        file_put_contents("$dir/main.php", "<?php\nfunction main(): void { (new App\\CounterApp())->run(); }\n");
        $root = dirname(__DIR__, 2);
        $runtime = "$root/src/Slint/Dev";
        $php = [...glob("$runtime/*.php"), "$root/src/App.php", "$root/src/Backend.php", "$root/src/Component.php", "$root/src/Screen.php", "$root/src/Window.php", "$root/src/Navigator.php", "$root/src/Store.php", "$root/src/RootStore.php", "$root/src/Events.php", "$root/src/Modules.php", "$root/src/ModuleUnavailable.php", "$root/src/Slint/SlintBackend.php", "$root/src/Slint/SlintWindow.php", "$root/src/Slint/backend.php", "$dir/Counter.php", "$dir/Stores.php", "$dir/main.php"];
        Bootstrap::write("$dir/boot.php", $php, ["$runtime/functions.php", "$root/src/Slint/backend.php", "$dir/main.php"]);

        [$proc, $pipes, $read, $shell] = $this->start($dir, restart: false);
        self::assertSame(['open', 'rk-screens', 'C', '300', '200'], $read());
        fwrite($shell, Protocol::encode('opened', '1'));
        self::assertSame(['set-int', '1', 'rk-page', '0'], $read());
        self::assertSame(['set-int', '1', 'p1', '0'], $read());
        self::assertSame(['set-string', '1', 'p2', ''], $read());
        self::assertSame(['set-string', '1', 'p3', ''], $read());
        self::assertSame(['run'], $read());
        fwrite($shell, Protocol::encode('event', '1', '1', ''));
        self::assertSame(['set-int', '1', 'p1', '1'], $read());
        fwrite($shell, Protocol::encode('event', '1', '2', "Ann\t✓"));
        fwrite($shell, Protocol::encode('event', '1', '3', 'ann'));
        self::assertSame(['set-string', '1', 'p3', 'ann'], $read(), 'a store action, then its value');
        fwrite($shell, Protocol::encode('reload'));
        self::assertSame(['set-int', '1', 'rk-page', '0'], $read(), 'reload pushes everything again');
        self::assertSame(['set-int', '1', 'p1', '1'], $read());
        self::assertSame(['set-string', '1', 'p2', "Ann\t✓"], $read());
        self::assertSame(['set-string', '1', 'p3', 'ann'], $read());
        // A PHP restart: the shell stops PHP (its connection closes) and starts it again.
        fclose($shell);
        $stderr = (string) stream_get_contents($pipes[2]);
        proc_close($proc);
        $state = json_decode((string) file_get_contents("$dir/state.json"), true);
        self::assertSame(['count' => 1, 'name' => "Ann\t✓"], $state['windows'][0]['props'] ?? null, $stderr);
        self::assertSame(['session' => ['userName' => 'ann']], $state['stores'] ?? null);

        [$proc, $pipes, $read, $shell] = $this->start($dir, restart: true);
        self::assertSame(['open', 'rk-screens', 'C', '300', '200'], $read());
        fwrite($shell, Protocol::encode('opened', '1'));
        self::assertSame(['set-int', '1', 'rk-page', '0'], $read());
        self::assertSame(['set-int', '1', 'p1', '1'], $read());
        self::assertSame(['set-string', '1', 'p2', "Ann\t✓"], $read());
        self::assertSame(['set-string', '1', 'p3', 'ann'], $read(), 'the store is back before the first sync');
        self::assertSame(['run'], $read());
        fwrite($shell, Protocol::encode('event', '1', '0', ''));
        self::assertSame(['close', '1'], $read());
        self::assertSame(['quit'], $read());
        fclose($shell);
        $stderr = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($proc), $stderr);
    }

    /** A collection and a list shown as images: one set-rows line each, with column types. */
    public function testRowsOverTheProtocol(): void
    {
        $dir = sys_get_temp_dir() . '/rk-devrows-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/screens.json", json_encode(['windows' => [[
            'name' => 'rk-screens', 'window' => null, 'frameless' => false, 'values' => [], 'actions' => [],
            'pages' => [[
                'class' => 'App\\Shelf', 'name' => 'Shelf', 'page' => 0, 'actions' => [],
                'values' => [
                    ['id' => 1, 'owner' => 'screen', 'source' => 'property', 'name' => 'devices', 'type' => 'rows', 'row' => ['item' => 'App\\Device', 'fields' => [
                        ['member' => 'name', 'source' => 'property', 'type' => 'string', 'name' => 'f0'],
                        ['member' => 'on', 'source' => 'property', 'type' => 'bool', 'name' => 'f1'],
                        ['member' => 'image', 'source' => 'property', 'type' => 'image', 'name' => 'f2'],
                    ]]],
                    ['id' => 2, 'owner' => 'screen', 'source' => 'property', 'name' => 'photos', 'type' => 'images'],
                    ['id' => 3, 'owner' => 'screen', 'source' => 'property', 'name' => 'devices', 'type' => 'rows', 'row' => ['item' => 'App\\Device', 'fields' => []]],
                ],
            ]],
        ]]]));
        file_put_contents("$dir/Shelf.php", "<?php\nnamespace App;\n"
            . "final class Device { public function __construct(public string \$name = '', public bool \$on = false, public string \$image = '') {} }\n"
            . "final class Devices extends \\RageKit\\Collection { public const OF = Device::class; }\n"
            . "final class Shelf extends \\RageKit\\Screen { public Devices \$devices; public array \$photos = ['/abs/p.png'];\n"
            . "    public function __construct() { \$this->devices = new Devices([new Device(\"a\\tb\", true, '/abs/x.png'), new Device('c')]); } }\n"
            . "final class ShelfApp extends \\RageKit\\App {\n"
            . "    protected function firstScreen(): \\RageKit\\Screen { return new Shelf(); }\n"
            . "    protected function title(): string { return 'S'; }\n"
            . "}\n");
        file_put_contents("$dir/main.php", "<?php\nfunction main(): void { (new App\\ShelfApp())->run(); }\n");
        $root = dirname(__DIR__, 2);
        $runtime = "$root/src/Slint/Dev";
        $php = [...glob("$runtime/*.php"), "$root/src/App.php", "$root/src/Backend.php", "$root/src/Component.php", "$root/src/Screen.php", "$root/src/Window.php", "$root/src/Navigator.php", "$root/src/Store.php", "$root/src/RootStore.php", "$root/src/Collection.php", "$root/src/Events.php", "$root/src/Modules.php", "$root/src/ModuleUnavailable.php", "$root/src/Slint/SlintBackend.php", "$root/src/Slint/SlintWindow.php", "$root/src/Slint/ImageValue.php", "$root/src/Slint/RowsValue.php", "$root/src/Slint/Images.php", "$root/src/Slint/backend.php", "$dir/Shelf.php", "$dir/main.php"];
        Bootstrap::write("$dir/boot.php", $php, ["$runtime/functions.php", "$root/src/Slint/backend.php", "$dir/main.php"]);

        [$proc, $pipes, $read, $shell] = $this->start($dir, restart: false);
        self::assertSame('open', $read()[0]);
        fwrite($shell, Protocol::encode('opened', '1'));
        self::assertSame(['set-int', '1', 'rk-page', '0'], $read());
        self::assertSame(['set-rows', '1', 'p1', 'struct', '2', '3', 's', 'b', 'img', "a\tb", '1', '/abs/x.png', 'c', '0', ''], $read());
        self::assertSame(['set-rows', '1', 'p2', 'images', '1', '1', 'img', '/abs/p.png'], $read());
        self::assertSame(['set-rows', '1', 'p3', 'struct', '2', '0'], $read(), 'a loop reading no members still has its rows');
        self::assertSame(['run'], $read());
        fwrite($shell, Protocol::encode('event', '1', '0', ''));
        self::assertSame(['close', '1'], $read());
        self::assertSame(['quit'], $read());
        fclose($shell);
        $stderr = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($proc), $stderr);
    }

    /** @return array{0: resource, 1: array<int, resource>, 2: \Closure(): list<string>, 3: resource} */
    private function start(string $dir, bool $restart): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $token = bin2hex(random_bytes(16));
        $proc = proc_open([PHP_BINARY, "$dir/boot.php"], [2 => ['pipe', 'w']], $pipes, $dir, [
            'RAGEKIT_DEV_SCREENS' => "$dir/screens.json", 'RAGEKIT_DEV_STATE' => "$dir/state.json", 'RAGEKIT_DEV_RESTART' => $restart ? '1' : '0',
            'RAGEKIT_DEV_CHANNEL' => stream_socket_get_name($listener, false), 'RAGEKIT_DEV_TOKEN' => $token,
        ] + getenv());
        $shell = stream_socket_accept($listener, 20);
        fclose($listener);
        if ($shell === false) {
            self::fail('PHP did not connect: ' . stream_get_contents($pipes[2]));
        }
        self::assertSame("$token\n", fgets($shell), 'the token comes first');
        $read = function () use ($shell, $pipes): array {
            $line = fgets($shell);
            if ($line === false) {
                self::fail('PHP ended early: ' . stream_get_contents($pipes[2]));
            }
            return Protocol::decode($line);
        };
        return [$proc, $pipes, $read, $shell];
    }
}
