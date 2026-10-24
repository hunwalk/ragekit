<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime;

use PHPUnit\Framework\TestCase;
use RageKit\Slint\Images;

require_once __DIR__ . '/FakeHost.php';
require_once __DIR__ . '/FakeScreens.php';

/** Bound image sources at runtime: registered assets, files, and downloaded web images. */
final class ImagesTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n0000";

    private RuntimeApp $app;
    private PictureScreen $screen;
    private string $cache;

    protected function setUp(): void
    {
        FakeHost::reset();
        Images::reset();
        $this->cache = sys_get_temp_dir() . '/rk-images-' . uniqid();
        mkdir($this->cache);
        FakeHost::$cacheDir = $this->cache;
        if (!function_exists('ragekit_backend')) {
            eval('function ragekit_backend(): \RageKit\Backend { return new \RageKit\Slint\SlintBackend(); }');
        }
        $this->app = new RuntimeApp();
        new RuntimeStore();
        $this->screen = new PictureScreen();
    }

    /** Opens the picture window with $cover, then forgets what that pushed. */
    private function open(string $cover = ''): void
    {
        $this->screen->cover = $cover;
        $this->app->open($this->screen, title: 'P');
        FakeHost::$calls = [];
    }

    /** A re-sync, as after any event (here the toast timer's tick). */
    private function sync(): void
    {
        FakeHost::$calls = [];
        (FakeHost::$dispatcher)(1, -2, '');
    }

    /** Ends download $n the way the http module would. */
    private function finish(int $n, string $error, string $contentType = '', string $bytes = self::PNG): void
    {
        $fetch = FakeHost::$fetches[$n];
        if ($error === '') {
            file_put_contents($fetch['file'], $bytes);
        }
        ($fetch['done'])($error, $contentType);
    }

    private function cached(string $url, string $ext): string
    {
        return $this->cache . '/' . Images::hash($url) . ".$ext";
    }

    public function testEmptySourceClearsOnce(): void
    {
        $this->app->open($this->screen, title: 'P');
        self::assertContains('image-file 1 6 ', FakeHost::$calls);
        $this->sync();
        self::assertSame([], FakeHost::$calls);
    }

    public function testRegisteredAssetUsesItsIndex(): void
    {
        $this->open();
        $this->screen->cover = 'assets/logo.png';
        $this->sync();
        self::assertSame(['image-asset 1 6 0'], FakeHost::$calls);
    }

    public function testRelativeFileResolvesNextToTheApp(): void
    {
        $this->open();
        $this->screen->cover = 'photos/a.png';
        $this->sync();
        self::assertSame(['image-file 1 6 /app/photos/a.png'], FakeHost::$calls);
    }

    /** Review Focus 2: one download per URL, however many images show it. */
    public function testUrlDownloadsOnceAndShowsWhenDone(): void
    {
        $url = 'https://example.com/a/cat.png?size=2';
        $this->open($url);
        $this->screen->icon = $url;
        $this->sync();
        self::assertCount(1, FakeHost::$fetches);
        self::assertSame($url, FakeHost::$fetches[0]['url']);
        self::assertSame($this->cache . '/' . Images::hash($url) . '.part', FakeHost::$fetches[0]['file']);
        self::assertSame([], FakeHost::$calls, 'nothing to show while it downloads');
        $this->finish(0, '', 'image/png');
        $this->sync();
        $file = $this->cached($url, 'png');
        self::assertSame(["image-file 1 6 $file", "image-file 1 7 $file"], FakeHost::$calls);
        self::assertFileExists($file);
        self::assertFileDoesNotExist(FakeHost::$fetches[0]['file']);
    }

    public function testHashIsSixteenHexDigits(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', Images::hash('https://example.com/x'));
        self::assertNotSame(Images::hash('https://example.com/x'), Images::hash('https://example.com/y'));
    }

    public function testExtensionFromContentType(): void
    {
        $url = 'https://example.com/avatar';
        $this->open($url);
        $this->finish(0, '', 'image/jpeg; charset=binary', 'bytes no sniffing recognises');
        $this->sync();
        self::assertSame(['image-file 1 6 ' . $this->cached($url, 'jpg')], FakeHost::$calls);
    }

    public function testExtensionFromTheFirstBytes(): void
    {
        $url = 'https://example.com/avatar';
        $this->open($url);
        $this->finish(0, '', '');
        $this->sync();
        self::assertSame(['image-file 1 6 ' . $this->cached($url, 'png')], FakeHost::$calls);
    }

    /** Review Focus 1: an HTML page instead of an image fails once. */
    public function testNotAnImage(): void
    {
        $url = 'https://example.com/page';
        $this->open($url);
        $this->finish(0, '', 'text/html', '<!doctype html><p>hi');
        self::assertSame(["error ragekit: image $url: not an image"], FakeHost::$calls, 'printed when the download ends');
        $this->sync();
        self::assertSame([], FakeHost::$calls);
        self::assertSame([], glob($this->cache . '/*'), 'nothing left in the cache');
    }

    /** Review Focus 1: a failed download prints once and isn't retried in this run. */
    public function testFailedDownloadPrintsOnceAndIsNotRetried(): void
    {
        $url = 'https://example.com/a.png';
        $this->open($url);
        $this->finish(0, 'HTTP 404');
        self::assertSame(["error ragekit: image $url: HTTP 404"], FakeHost::$calls, 'printed when the download ends');
        for ($i = 0; $i < 10; $i++) {
            $this->sync();
            self::assertSame([], FakeHost::$calls);
        }
        self::assertCount(1, FakeHost::$fetches);
    }

    /** Review Focus 3: switching to B before A finishes shows B, and A finishing later doesn't replace it. */
    public function testSwitchingUrlsBeforeTheDownloadEnds(): void
    {
        $a = 'https://example.com/a.png';
        $b = 'https://example.com/b.png';
        $this->open($a);
        $this->screen->cover = $b;
        $this->sync();
        $this->finish(1, '', 'image/png');
        $this->sync();
        self::assertSame(['image-file 1 6 ' . $this->cached($b, 'png')], FakeHost::$calls);
        $this->finish(0, '', 'image/png');
        $this->sync();
        self::assertSame([], FakeHost::$calls, 'A is cached but not shown');
    }

    /** Review Focus 5: a URL already in the cache from an earlier run shows without downloading. */
    public function testCacheHitSkipsTheDownload(): void
    {
        $url = 'https://example.com/a.gif';
        file_put_contents($this->cached($url, 'gif'), 'GIF89a');
        $this->open();
        $this->screen->cover = $url;
        $this->sync();
        self::assertSame(['image-file 1 6 ' . $this->cached($url, 'gif')], FakeHost::$calls);
        self::assertSame([], FakeHost::$fetches);
    }

    /** Review Focus 4: a file that can't be loaded prints once, then shows nothing. */
    public function testBrokenFilePrintsOnce(): void
    {
        $this->open();
        $this->screen->cover = 'photos/broken.png';
        $this->sync();
        self::assertSame(['image-file 1 6 /app/photos/broken.png', "error ragekit: image photos/broken.png: couldn't be loaded"], FakeHost::$calls);
        $this->sync();
        self::assertSame(['image-file 1 6 '], FakeHost::$calls, 'cleared once');
        $this->sync();
        self::assertSame([], FakeHost::$calls);
    }

    public function testWebImagesNeedTheHttpModule(): void
    {
        FakeHost::$httpModule = false;
        $url = 'https://example.com/a.png';
        $this->open($url);
        $this->sync();
        $this->sync();
        $errors = array_values(array_filter(FakeHost::$calls, fn(string $c) => str_starts_with($c, 'error')));
        self::assertSame([], $errors, 'printed once, when the window opened');
        Images::reset();
        $this->screen->cover = 'https://example.com/b.png';
        $this->sync();
        self::assertSame(['error ragekit: image https://example.com/b.png: web images need the http module (add http to ragekit: modules: in project.yml)'], FakeHost::$calls);
    }

    /** Final review: a downloaded file the bridge can't decode leaves the cache, so a later run downloads again. */
    public function testUndecodableDownloadIsEvictedFromTheCache(): void
    {
        $url = 'https://example.com/a.png';
        FakeHost::$unloadable = [$this->cached($url, 'png')];
        $this->open($url);
        $this->finish(0, '', 'image/png');
        $this->sync();
        self::assertSame(['image-file 1 6 ' . $this->cached($url, 'png'), "error ragekit: image $url: couldn't be loaded"], FakeHost::$calls);
        self::assertFileDoesNotExist($this->cached($url, 'png'));
    }

    /** Final review: an HTML answer is not an image, even with a .png URL or an inline <svg>. */
    public function testHtmlIsNeverAnImage(): void
    {
        $png = 'https://example.com/login/avatar.png';
        $bare = 'https://example.com/avatar';
        $this->open($png);
        $this->screen->icon = $bare;
        $this->sync();
        $this->finish(0, '', 'text/html; charset=utf-8', '<html><body>Sign in');
        $this->finish(1, '', '', '<!doctype html><html><svg viewBox="0 0 1 1"></svg>');
        self::assertSame(["error ragekit: image $png: not an image", "error ragekit: image $bare: not an image"], FakeHost::$calls);
        self::assertSame([], glob($this->cache . '/*'));
    }

    /** Final review: the bytes decide the extension, not the URL (a CDN may send JPEG for .png). */
    public function testBytesWinOverTheUrlExtension(): void
    {
        $url = 'https://cdn.example.com/a.png';
        $this->open($url);
        $this->finish(0, '', 'image/jpeg', "\xFF\xD8\xFF\xE0rest");
        $this->sync();
        self::assertSame(['image-file 1 6 ' . $this->cached($url, 'jpg')], FakeHost::$calls);
    }
}
