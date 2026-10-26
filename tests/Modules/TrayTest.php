<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;
use RageKit\Events;
use RageKit\Tests\Slint\Runtime\FakeHost;
use RageKit\Tray\Tray;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';
require_once __DIR__ . '/../../modules/tray/src/Tray.php';

/**
 * The tray module: its PHP API over fake \tray_* functions, and its library
 * (tests/fixtures/modules/probe_tray.cc) driven by tests/fixtures/tray/drive.sh the way a tray host
 * would, in a throwaway D-Bus session with a fake StatusNotifierWatcher.
 */
final class TrayTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        FakeHost::reset();
        FakeHost::$modules['tray'] = '';
    }

    public function testMenuAndClicks(): void
    {
        $got = [];
        $tray = Tray::show('/abs/icon.png', 'My app', function () use (&$got): void { $got[] = 'click'; });
        $tray->item('Open', function () use (&$got): void { $got[] = 'open'; })
            ->separator()
            ->item('Quit', function () use (&$got): void { $got[] = 'quit'; })
            ->tooltip('3 unread');
        self::assertSame(['tray /abs/icon.png|My app', 'tray-menu Open', 'tray-menu Open|-', 'tray-menu Open|-|Quit', 'tray-tooltip 3 unread'], FakeHost::$calls);
        Events::deliver('tray', 1, 'activate', '');
        Events::deliver('tray', 1, 'menu', '2');
        Events::deliver('tray', 1, 'menu', '1'); // the separator: nothing
        Events::deliver('tray', 1, 'menu', '9'); // gone: nothing
        self::assertSame(['click', 'quit'], $got);
        self::assertSame($tray, Tray::show('/abs/icon.png'), 'one tray per app');
        $tray->remove();
        Events::deliver('tray', 1, 'activate', '');
        self::assertSame(['click', 'quit'], $got, 'nothing after remove()');
    }

    public function testMenuItemsNeedALabel(): void
    {
        $tray = Tray::show('/abs/icon.png');
        $this->expectException(\InvalidArgumentException::class);
        try {
            $tray->item(' ', function (): void {});
        } finally {
            $tray->remove();
        }
    }

    public function testLibraryAsATrayHostSeesIt(): void
    {
        foreach (['g++', 'dbus-run-session', 'gdbus', 'python3'] as $tool) {
            if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec("command -v $tool")) === '') {
                self::markTestSkipped("needs Linux and $tool");
            }
        }
        exec('python3 -c ' . escapeshellarg('from gi.repository import Gio') . ' 2>/dev/null', $out, $gi);
        if ($gi !== 0) {
            self::markTestSkipped('needs python3-gi (the fake StatusNotifierWatcher)');
        }
        $dir = sys_get_temp_dir() . '/rk-tray-' . uniqid();
        mkdir("$dir/icons", 0777, true);
        copy(self::ROOT . '/tests/e2e/images/assets/red.png', "$dir/icons/app-tray.png");
        [$lib, $probe] = $this->build($dir);
        exec('env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg($dir) . ' timeout 60 dbus-run-session -- sh '
            . escapeshellarg(self::ROOT . '/tests/fixtures/tray/drive.sh') . ' ' . implode(' ', array_map('escapeshellarg', [$probe, $lib, "$dir/icons/app-tray.png", $dir]))
            . ' >/dev/null 2>&1', $ignored, $code);
        $probeLog = (string) file_get_contents("$dir/probe.log");
        self::assertStringContainsString("available []\nshow 1\n", $probeLog);
        self::assertStringContainsString("event 1 activate \n", $probeLog, 'a click on the icon');
        self::assertStringContainsString("event 1 menu 2\n", $probeLog, '"Quit", the third menu entry');
        self::assertStringEndsWith("done\n", $probeLog);
        self::assertMatchesRegularExpression('/^register org\.kde\.StatusNotifierItem-\d+-1 :/m', (string) file_get_contents("$dir/watcher.log"));
        $host = (string) file_get_contents("$dir/host.log");
        self::assertStringContainsString("IconName (<'app-tray'>,)", $host);
        self::assertStringContainsString("IconThemePath (<'$dir/icons'>,)", $host);
        self::assertStringContainsString("ToolTip (<('', @a(iiay) [], \"3 unread 'quoted'\", '')>,)", $host);
        self::assertStringContainsString("Menu (<objectpath '/MenuBar'>,)", $host);
        self::assertStringContainsString("[<(1, {'label': <'Open__now'>, 'enabled': <true>, 'visible': <true>}, @av [])>, <(2, {'type': <'separator'>}, @av [])>, <(3, {'label': <'Quit'>", $host);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** @return array{0: string, 1: string} the library and the probe */
    private function build(string $dir): array
    {
        $root = (string) realpath(self::ROOT);
        $module = Module::load("$root/modules/tray/module.yml", $root);
        file_put_contents("$dir/rk_module_tray.h", ModuleEmitter::header($module));
        file_put_contents("$dir/rk_module_tray_call.cc", ModuleEmitter::callEntry($module));
        exec("g++ -std=c++17 -O1 -Wall -Wextra -Werror -Wno-unused-function -shared -fPIC -fvisibility=hidden $root/modules/tray/native/*.cc"
            . " $dir/rk_module_tray_call.cc -I$dir -I$root/native/include -I$root/modules/tray/native -ldl -lpthread -o $dir/librk_module_tray.so 2>&1"
            . " && g++ -std=c++17 $root/tests/fixtures/modules/probe_tray.cc -ldl -lpthread -o $dir/probe_tray 2>&1", $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        return ["$dir/librk_module_tray.so", "$dir/probe_tray"];
    }
}
