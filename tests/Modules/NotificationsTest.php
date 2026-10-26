<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;
use RageKit\Events;
use RageKit\ModuleUnavailable;
use RageKit\Notifications\Notification;
use RageKit\Tests\Slint\Runtime\FakeHost;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';
require_once __DIR__ . '/../../modules/notifications/src/Notification.php';

/**
 * The notifications module: its PHP API over fake \notifications_* functions, and its library
 * (tests/fixtures/modules/probe_notifications.cc) against tests/fixtures/notifications/fake_server.py
 * in a throwaway D-Bus session.
 */
final class NotificationsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        FakeHost::reset();
        FakeHost::$modules['notifications'] = '';
        for ($id = 1; $id <= 3; $id++) {
            Events::off('notifications', $id);
        }
    }

    public function testClickAndClose(): void
    {
        $got = [];
        $n = Notification::show('Bob', 'hi', function () use (&$got): void { $got[] = 'click'; }, function () use (&$got): void { $got[] = 'close'; });
        self::assertSame(['notify Bob|hi'], FakeHost::$calls);
        self::assertTrue($n->open());
        Events::deliver('notifications', $n->id, 'clicked', '');
        Events::deliver('notifications', $n->id, 'closed', '');
        Events::deliver('notifications', $n->id, 'clicked', '');
        self::assertSame(['click', 'close'], $got, 'nothing after it closed');
        self::assertFalse($n->open());
        $n->close();
        self::assertNotContains("close-notification $n->id", FakeHost::$calls, 'closing a closed one does nothing');
    }

    public function testCloseFromTheApp(): void
    {
        $n = Notification::show('Bob');
        $n->close();
        self::assertSame(['notify Bob|', "close-notification $n->id"], FakeHost::$calls);
    }

    public function testWithoutAServiceShowThrowsAndWhySaysWhy(): void
    {
        FakeHost::$notificationService = 'no notification service: ServiceUnknown';
        self::assertFalse(Notification::available());
        self::assertSame('no notification service: ServiceUnknown', Notification::why());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the notification couldn\'t be shown: no notification service: ServiceUnknown');
        Notification::show('Bob');
    }

    public function testWithoutTheModule(): void
    {
        unset(FakeHost::$modules['notifications']);
        self::assertFalse(Notification::available());
        $this->expectException(ModuleUnavailable::class);
        Notification::show('Bob');
    }

    public function testLibraryAgainstAFakeNotificationService(): void
    {
        foreach (['g++', 'dbus-run-session', 'gdbus', 'python3'] as $tool) {
            if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec("command -v $tool")) === '') {
                self::markTestSkipped("needs Linux and $tool");
            }
        }
        exec('python3 -c ' . escapeshellarg('from gi.repository import Gio') . ' 2>/dev/null', $out, $gi);
        if ($gi !== 0) {
            self::markTestSkipped('needs python3-gi (the fake notification service)');
        }
        [$lib, $probe] = $this->build();
        $dir = sys_get_temp_dir() . '/rk-notify-' . uniqid();
        mkdir($dir);
        $server = escapeshellarg(self::ROOT . '/tests/fixtures/notifications/fake_server.py');
        $log = escapeshellarg("$dir/server.log");
        $script = "python3 $server $log & for i in \$(seq 50); do grep -q ready $log 2>/dev/null && break; sleep 0.1; done; "
            . escapeshellarg($probe) . ' ' . escapeshellarg($lib);
        exec('env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg($dir) . ' timeout 60 dbus-run-session -- sh -c ' . escapeshellarg($script) . ' 2>/dev/null', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        self::assertSame(['available []', 'show 1 []', 'clicked yes', 'closed-after-click yes', 'show 2', 'closed-by-app yes', 'show-after-reset 3', 'done'],
            array_values(array_filter($lines, fn(string $l) => !str_starts_with($l, 'event '))));
        self::assertSame([
            'ready',
            'notify 1 app=probe_notifications summary=Bob body=a &lt;b&gt; &amp; c actions=default,Open',
            'click 1',
            'notify 2 app=probe_notifications summary=Cyd body= actions=default,Open',
            'close 2',
            'notify 3 app=probe_notifications summary=Dan body= actions=default,Open',
        ], file("$dir/server.log", FILE_IGNORE_NEW_LINES), 'the body is escaped: servers read it as markup');

        // No service on the bus: it says why, at once.
        exec('env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg($dir) . ' timeout 30 dbus-run-session -- ' . escapeshellarg($probe) . ' ' . escapeshellarg($lib) . ' available 2>/dev/null', $none, $code);
        self::assertSame(0, $code);
        self::assertStringStartsWith('available [no notification service: ', $none[0]);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** @return array{0: string, 1: string} the library and the probe */
    private function build(): array
    {
        $dir = sys_get_temp_dir() . '/rk-notifylib-' . uniqid();
        mkdir($dir);
        $root = (string) realpath(self::ROOT);
        $module = Module::load("$root/modules/notifications/module.yml", $root);
        file_put_contents("$dir/rk_module_notifications.h", ModuleEmitter::header($module));
        file_put_contents("$dir/rk_module_notifications_call.cc", ModuleEmitter::callEntry($module));
        exec("g++ -std=c++17 -O1 -Wall -Wextra -Werror -Wno-unused-function -shared -fPIC -fvisibility=hidden $root/modules/notifications/native/*.cc"
            . " $dir/rk_module_notifications_call.cc -I$dir -I$root/native/include -I$root/modules/notifications/native -ldl -lpthread"
            . " -o $dir/librk_module_notifications.so 2>&1 && g++ -std=c++17 $root/tests/fixtures/modules/probe_notifications.cc -ldl -lpthread -o $dir/probe_notifications 2>&1", $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        return ["$dir/librk_module_notifications.so", "$dir/probe_notifications"];
    }
}
