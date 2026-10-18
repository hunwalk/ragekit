<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Dev\Bootstrap;
use RageKit\Slint\Dev\Protocol;

require_once __DIR__ . '/../../src/Slint/Dev/Protocol.php';

/**
 * The dev Screens.php + functions.php with the real SlintBackend, in a subprocess (they declare
 * the same functions/class as the runtime tests' fakes). This test plays the shell on fds 3/4.
 */
final class DevAppTest extends TestCase
{
    public function testRealBackendOverTheProtocol(): void
    {
        $dir = sys_get_temp_dir() . '/rk-devapp-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/screens.json", json_encode(['screens' => [[
            'class' => 'App\\Counter', 'name' => 'Counter',
            'values' => [['id' => 1, 'source' => 'property', 'name' => 'count', 'type' => 'int'], ['id' => 2, 'source' => 'property', 'name' => 'name', 'type' => 'string']],
            'actions' => [['id' => 1, 'kind' => 'call', 'target' => 'add', 'args' => [], 'params' => []], ['id' => 2, 'kind' => 'set', 'target' => 'name', 'binding' => 2, 'type' => 'string']],
        ]]]));
        file_put_contents("$dir/Counter.php", "<?php\nnamespace App;\nfinal class Counter extends \\RageKit\\Screen { public int \$count = 0; public string \$name = ''; public function add(): void { \$this->count++; } }\n");
        file_put_contents("$dir/main.php", "<?php\nfunction main(): void { \$app = new \\RageKit\\App(); \$app->open(new App\\Counter(), 'C', 300, 200); \$app->run(); }\n");
        $root = dirname(__DIR__, 2);
        $runtime = "$root/src/Slint/Dev";
        $php = [...glob("$runtime/*.php"), "$root/src/App.php", "$root/src/Backend.php", "$root/src/Component.php", "$root/src/Screen.php", "$root/src/Events.php", "$root/src/Modules.php", "$root/src/ModuleUnavailable.php", "$root/src/Slint/SlintBackend.php", "$root/src/Slint/SlintWindow.php", "$root/src/Slint/backend.php", "$dir/Counter.php", "$dir/main.php"];
        Bootstrap::write("$dir/boot.php", $php, ["$runtime/functions.php", "$root/src/Slint/backend.php", "$dir/main.php"]);

        $proc = proc_open([PHP_BINARY, "$dir/boot.php"], [3 => ['pipe', 'r'], 4 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir,
            ['RAGEKIT_DEV_SCREENS' => "$dir/screens.json", 'RAGEKIT_DEV_STATE' => "$dir/state.json"] + getenv());
        $read = function () use ($pipes): array {
            $line = fgets($pipes[4]);
            if ($line === false) {
                self::fail('PHP ended early: ' . stream_get_contents($pipes[2]));
            }
            return Protocol::decode($line);
        };
        self::assertSame(['open', 'Counter', 'C', '300', '200'], $read());
        fwrite($pipes[3], Protocol::encode('opened', '1'));
        self::assertSame(['set-int', '1', 'p1', '0'], $read());
        self::assertSame(['set-string', '1', 'p2', ''], $read());
        self::assertSame(['run'], $read());
        fwrite($pipes[3], Protocol::encode('event', '1', '1', ''));
        self::assertSame(['set-int', '1', 'p1', '1'], $read());
        fwrite($pipes[3], Protocol::encode('event', '1', '2', "Ann\t✓"));
        fwrite($pipes[3], Protocol::encode('reload'));
        self::assertSame(['set-int', '1', 'p1', '1'], $read(), 'reload pushes everything again');
        self::assertSame(['set-string', '1', 'p2', "Ann\t✓"], $read());
        fwrite($pipes[3], Protocol::encode('event', '1', '0', ''));
        self::assertSame(['close', '1'], $read());
        self::assertSame(['quit'], $read());
        fclose($pipes[3]);
        $stderr = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($proc), $stderr);
        $props = json_decode((string) file_get_contents("$dir/state.json"), true)['windows'][0]['props'] ?? null;
        self::assertSame(['count' => 1, 'name' => "Ann\t✓"], $props, 'the last snapshot before quitting is kept');
    }
}
