<?php

declare(strict_types=1);

namespace RageKit\Cli\Fetch;

use RageKit\Cli\RageKitException;

/**
 * Fetches a Dependency when it's missing or doesn't match what's declared: a file is checked by
 * its sha256, a git folder by the commit its tag points to (recorded in a marker file).
 */
final class Fetcher
{
    private const MARKER = '.ragekit-fetched';

    /** @return bool whether it was fetched (false: already there) */
    public static function ensure(Dependency $dependency): bool
    {
        return $dependency->url !== null ? self::file($dependency) : self::git($dependency);
    }

    /** Whether it's there as declared, without fetching. */
    public static function present(Dependency $dependency): bool
    {
        if ($dependency->url !== null) {
            return is_file($dependency->target) && hash_file('sha256', $dependency->target) === $dependency->sha256;
        }
        return @file_get_contents($dependency->target . '/' . self::MARKER) === $dependency->commit . "\n";
    }

    private static function file(Dependency $dependency): bool
    {
        if (self::present($dependency)) {
            return false;
        }
        $data = @file_get_contents((string) $dependency->url);
        if ($data === false && str_starts_with((string) $dependency->url, 'http')) {
            // No https in this PHP (no openssl / allow_url_fopen off): use curl.
            $data = shell_exec('curl -fsSL ' . escapeshellarg((string) $dependency->url) . ' 2>/dev/null');
        }
        if (!is_string($data) || $data === '') {
            throw new RageKitException("could not download {$dependency->url}");
        }
        $actual = hash('sha256', $data);
        if ($actual !== $dependency->sha256) {
            throw new RageKitException("{$dependency->url}: checksum mismatch (expected {$dependency->sha256}, got $actual)");
        }
        @mkdir(dirname($dependency->target), 0777, true);
        file_put_contents($dependency->target . '.part', $data);
        rename($dependency->target . '.part', $dependency->target);
        return true;
    }

    private static function git(Dependency $dependency): bool
    {
        if (self::present($dependency)) {
            return false;
        }
        $tmp = $dependency->target . '.part';
        self::remove($tmp);
        $run = function (string $command) use ($tmp): string {
            exec($command . ' 2>&1', $out, $code);
            if ($code !== 0) {
                self::remove($tmp);
                throw new RageKitException("git failed: $command\n" . implode("\n", $out));
            }
            return trim(implode("\n", $out));
        };
        $run('git clone -q --depth 1 --branch ' . escapeshellarg((string) $dependency->ref) . ' --filter=blob:none --sparse '
            . escapeshellarg((string) $dependency->git) . ' ' . escapeshellarg($tmp));
        if ($dependency->path !== '') {
            $run('git -C ' . escapeshellarg($tmp) . ' sparse-checkout set ' . escapeshellarg($dependency->path));
        }
        $commit = $run('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD');
        if ($commit !== $dependency->commit) {
            self::remove($tmp);
            throw new RageKitException("{$dependency->git}: {$dependency->ref} is commit $commit, expected {$dependency->commit}");
        }
        self::remove($dependency->target);
        @mkdir(dirname($dependency->target), 0777, true);
        rename($dependency->path === '' ? $tmp : "$tmp/{$dependency->path}", $dependency->target);
        self::remove($tmp);
        file_put_contents($dependency->target . '/' . self::MARKER, $dependency->commit . "\n");
        return true;
    }

    /** Deletes a file or a whole folder (nothing if it isn't there). */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
