<?php

namespace RageKit\Slint;

use RageKit\App;
use RageKit\Backend;
use RageKit\Generated\Screens;

/**
 * What a bound image source shows: a registered asset (embedded), a file, or a web image
 * downloaded once (http module) into the app's cache folder and kept there. state() answers
 * with a key SlintWindow compares and pushes: 'none', 'asset:<index>' or 'file:<path>'.
 * Problems print one line per source and aren't retried until the app restarts.
 */
final class Images
{
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp'];

    /** @var array<string, string> URL => cached file, '' while it downloads */
    private static array $web = [];

    /** @var array<string, bool> sources that failed (already reported) */
    private static array $failed = [];

    private static string $cacheDir = '';

    public static function state(Backend $backend, string $source): string
    {
        if ($source === '' || isset(self::$failed[$source])) {
            return 'none';
        }
        $index = Screens::assetIndex($source);
        if ($index >= 0) {
            return 'asset:' . $index;
        }
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            return self::web($backend, $source);
        }
        return 'file:' . App::resolve($source);
    }

    /**
     * The bridge couldn't load what state() pointed to: report it once and show nothing. A
     * downloaded file leaves the cache, so the next run downloads it again.
     */
    public static function loadFailed(Backend $backend, string $source): void
    {
        if (isset(self::$web[$source]) && self::$web[$source] !== '') {
            self::remove(self::$web[$source]);
            self::$web[$source] = '';
        }
        self::fail($backend, $source, "couldn't be loaded");
    }

    /** 16 hex digits naming $url's file in the cache (two 32-bit FNV-1a hashes). */
    public static function hash(string $url): string
    {
        $a = 2166136261;
        $b = 2166136261 ^ 16777619;
        foreach (str_split($url) as $char) {
            $a = (($a ^ ord($char)) * 16777619) & 0xFFFFFFFF;
            $b = (($b ^ ord($char)) * 16777619) & 0xFFFFFFFF;
            $b = (($b ^ 0x5F) * 16777619) & 0xFFFFFFFF;
        }
        return sprintf('%08x%08x', $a, $b);
    }

    /** Forgets downloads and failures of this run (tests). */
    public static function reset(): void
    {
        self::$web = [];
        self::$failed = [];
        self::$cacheDir = '';
    }

    private static function web(Backend $backend, string $url): string
    {
        if (isset(self::$web[$url])) {
            return self::$web[$url] === '' ? 'none' : 'file:' . self::$web[$url];
        }
        if (self::$cacheDir === '') {
            self::$cacheDir = \slint_cache_dir();
        }
        $base = self::$cacheDir . '/' . self::hash($url);
        foreach (self::EXTENSIONS as $ext) {
            if (file_exists("$base.$ext")) {
                self::$web[$url] = "$base.$ext";
                return 'file:' . "$base.$ext";
            }
        }
        self::$web[$url] = '';
        $part = "$base.part";
        $started = Screens::fetchImage($url, $part, function (string $error, string $contentType) use ($backend, $url, $part, $base): void {
            self::downloaded($backend, $url, $part, $base, $error, $contentType);
        });
        if (!$started) {
            self::fail($backend, $url, 'web images need the http module (add http to ragekit: modules: in project.yml)');
        }
        return 'none';
    }

    private static function downloaded(Backend $backend, string $url, string $part, string $base, string $error, string $contentType): void
    {
        if ($error !== '') {
            self::remove($part);
            self::fail($backend, $url, $error);
            return;
        }
        $ext = self::extension($url, $contentType, $part);
        if ($ext === '') {
            self::remove($part);
            self::fail($backend, $url, 'not an image');
            return;
        }
        if (!rename($part, "$base.$ext")) {
            self::remove($part);
            self::fail($backend, $url, "couldn't be saved to $base.$ext");
            return;
        }
        self::$web[$url] = "$base.$ext";
    }

    /**
     * The file's first bytes decide; else the Content-Type, else the URL's path. '' when it isn't an
     * image: nothing recognisable, or a Content-Type that isn't image/* (an HTML login page at a
     * .png URL).
     */
    private static function extension(string $url, string $contentType, string $file): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        if ($type !== '' && !str_starts_with($type, 'image/') && $type !== 'application/octet-stream' && $type !== 'binary/octet-stream') {
            return '';
        }
        $sniffed = self::sniff($file);
        if ($sniffed !== '') {
            return $sniffed;
        }
        $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/svg+xml' => 'svg', 'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp'];
        if (isset($types[$type])) {
            return $types[$type];
        }
        $ext = strtolower(pathinfo((string) parse_url($url, \PHP_URL_PATH), \PATHINFO_EXTENSION));
        return $type === '' && in_array($ext, self::EXTENSIONS, true) ? $ext : '';
    }

    /** An image format from a file's first bytes, or ''. */
    private static function sniff(string $file): string
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        $head = (string) fread($handle, 256);
        fclose($handle);
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, 'GIF8')) {
            return 'gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($head, 'BM')) {
            return 'bmp';
        }
        // An SVG document, not an HTML page that happens to inline one.
        $text = ltrim($head);
        return str_starts_with($text, '<svg') || (str_starts_with($text, '<?xml') && str_contains($text, '<svg')) ? 'svg' : '';
    }

    private static function fail(Backend $backend, string $source, string $reason): void
    {
        if (isset(self::$failed[$source])) {
            return;
        }
        self::$failed[$source] = true;
        $backend->printError("ragekit: image $source: $reason");
    }

    private static function remove(string $file): void
    {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
