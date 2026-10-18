<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

/**
 * Polls files for changes by content (mtimes only have one-second resolution). Changes are
 * reported once every changed file has stayed the same for 150 ms, so an editor's burst of writes
 * (or a save via rename, or several files saved together) is one batch.
 */
final class Watcher
{
    private const SETTLE = 0.15;

    /** @var array<string, string> path => kind */
    private array $files = [];

    /** @var array<string, string|null> path => content hash last reported (null = missing) */
    private array $known = [];

    /** @var array<string, array{0: string|null, 1: float}> path => [hash seen, when it was first seen] */
    private array $pending = [];

    /** @param array<string, string> $files absolute path => kind (template, php, native) */
    public function __construct(array $files)
    {
        $this->setFiles($files);
    }

    /** @param array<string, string> $files absolute path => kind; files new to the list are not a change */
    public function setFiles(array $files): void
    {
        foreach ($files as $path => $kind) {
            if (!array_key_exists($path, $this->known)) {
                $this->known[$path] = self::hash($path);
            }
        }
        $this->known = array_intersect_key($this->known, $files);
        $this->pending = array_intersect_key($this->pending, $files);
        $this->files = $files;
    }

    /** @return array<string, list<string>> kind => files that changed and settled since the last report */
    public function poll(): array
    {
        $now = microtime(true);
        $settled = true;
        foreach ($this->files as $path => $kind) {
            $hash = self::hash($path);
            if ($hash === $this->known[$path]) {
                unset($this->pending[$path]);
                continue;
            }
            if (!isset($this->pending[$path]) || $this->pending[$path][0] !== $hash) {
                $this->pending[$path] = [$hash, $now];
            }
            if ($now - $this->pending[$path][1] < self::SETTLE) {
                $settled = false;
            }
        }
        // One batch: nothing is reported while any changed file is still being written, so a
        // save touching several files restarts the app once.
        if (!$settled) {
            return [];
        }
        $changed = [];
        foreach ($this->files as $path => $kind) {
            if (isset($this->pending[$path])) {
                $this->known[$path] = $this->pending[$path][0];
                $changed[$kind][] = $path;
            }
        }
        $this->pending = [];
        foreach ($changed as &$paths) {
            sort($paths);
        }
        return $changed;
    }

    private static function hash(string $path): ?string
    {
        clearstatcache(true, $path);
        $hash = is_file($path) ? @md5_file($path) : false;
        return $hash === false ? null : $hash;
    }
}
