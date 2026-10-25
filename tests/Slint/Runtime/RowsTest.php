<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime;

use PHPUnit\Framework\TestCase;
use RageKit\Slint\Images;

require_once __DIR__ . '/FakeHost.php';
require_once __DIR__ . '/FakeScreens.php';

/** Rows (collections, lists shown as images) pushed by SlintWindow::sync(). */
final class RowsTest extends TestCase
{
    private RuntimeApp $app;
    private ShelfScreen $screen;
    private string $cache;

    protected function setUp(): void
    {
        FakeHost::reset();
        Images::reset();
        $this->cache = sys_get_temp_dir() . '/rk-rows-' . uniqid();
        mkdir($this->cache);
        FakeHost::$cacheDir = $this->cache;
        if (!function_exists('ragekit_backend')) {
            eval('function ragekit_backend(): \RageKit\Backend { return new \RageKit\Slint\SlintBackend(); }');
        }
        $this->app = new RuntimeApp();
        new RuntimeStore();
        $this->screen = new ShelfScreen();
    }

    /** @param list<array{0: string, 1: string}> $rows */
    private function open(array $rows): void
    {
        $this->screen->rows = $rows;
        $this->app->open($this->screen, title: 'S');
        FakeHost::$calls = [];
    }

    private function sync(): void
    {
        FakeHost::$calls = [];
        (FakeHost::$dispatcher)(1, -2, '');
    }

    /** @return list<string> */
    private function pushes(): array
    {
        return array_values(array_filter(FakeHost::$calls, fn(string $c) => str_starts_with($c, 'rows 1 8')));
    }

    public function testRowsPushedOnceAndOnChange(): void
    {
        $this->app->open($this->screen, title: 'S');
        $this->screen->rows = [['a', '']];
        $this->sync();
        self::assertSame(['rows 1 8', '  row', '  s a', '  file ', 'rows end'], FakeHost::$calls);
        $this->sync();
        self::assertSame([], FakeHost::$calls);
        $this->screen->rows = [['b', '']];
        $this->sync();
        self::assertSame(['rows 1 8', '  row', '  s b', '  file ', 'rows end'], FakeHost::$calls);
    }

    /** Review Focus 3: a rescan clears the list, then refills it. */
    public function testClearedListPushesEmpty(): void
    {
        $this->open([['a', ''], ['b', '']]);
        $this->screen->rows = [];
        $this->sync();
        self::assertSame(['rows 1 8', 'rows end'], FakeHost::$calls);
        $this->screen->rows = [['c', '']];
        $this->sync();
        self::assertSame(['rows 1 8', '  row', '  s c', '  file ', 'rows end'], FakeHost::$calls);
    }

    public function testImageCellsResolveLikeSingleImages(): void
    {
        $this->open([]);
        $this->screen->rows = [['x', 'assets/logo.png'], ['y', 'photos/a.png'], ['z', '']];
        $this->sync();
        self::assertSame(['rows 1 8', '  row', '  s x', '  asset 0', '  row', '  s y', '  file /app/photos/a.png', '  row', '  s z', '  file ', 'rows end'], FakeHost::$calls);
    }

    /** Review Focus 4: one download finishing pushes the list once, with that row's file. */
    public function testOneImageArrivingPushesOnce(): void
    {
        $url = 'https://example.com/a.png';
        $this->open([['a', 'assets/logo.png'], ['b', $url]]);
        $this->sync();
        self::assertSame([], $this->pushes(), 'nothing new while it downloads');
        $fetch = FakeHost::$fetches[0];
        file_put_contents($fetch['file'], "\x89PNG\r\n\x1a\n0000");
        ($fetch['done'])('', 'image/png');
        $this->sync();
        $file = $this->cache . '/' . Images::hash($url) . '.png';
        self::assertSame(['rows 1 8', '  row', '  s a', '  asset 0', '  row', '  s b', "  file $file", 'rows end'], FakeHost::$calls);
        $this->sync();
        self::assertSame([], FakeHost::$calls);
    }

    public function testBrokenRowImageReportedOnce(): void
    {
        $this->open([]);
        $this->screen->rows = [['a', 'photos/broken.png']];
        $errors = [];
        for ($i = 0; $i < 4; $i++) {
            $this->sync();
            $errors = [...$errors, ...array_filter(FakeHost::$calls, fn(string $c) => str_starts_with($c, 'error'))];
        }
        self::assertSame(["error ragekit: image photos/broken.png: couldn't be loaded"], array_values($errors));
    }
}
