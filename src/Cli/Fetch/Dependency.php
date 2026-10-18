<?php

declare(strict_types=1);

namespace RageKit\Cli\Fetch;

use RageKit\Cli\RageKitException;

/**
 * Third-party code a kit or module needs, declared under `fetch:` in its kit.yml / module.yml:
 *
 *   - url: https://…/miniaudio.h             a single file, checked by its sha256
 *     sha256: 9019…
 *     to: native/third_party/miniaudio/miniaudio.h
 *   - git: https://github.com/slint-ui/slint.git
 *     ref: v1.18.1                           a folder of a repository, at a tag whose commit
 *     commit: 372cf0ee…                      must match
 *     path: ui-libraries/material/src
 *     to: library
 *
 * `to` is relative to the kit's / module's folder (and git-ignored there).
 */
final class Dependency
{
    private function __construct(
        public readonly string $target,
        public readonly ?string $url = null,
        public readonly ?string $sha256 = null,
        public readonly ?string $git = null,
        public readonly ?string $ref = null,
        public readonly ?string $commit = null,
        public readonly string $path = '',
    ) {
    }

    /** @param array<string, mixed> $spec */
    public static function fromArray(array $spec, string $packageDir, string $file, int $index = 0): self
    {
        $where = "$file: fetch[$index]";
        $to = (string) ($spec['to'] ?? '');
        $isFile = isset($spec['url'], $spec['sha256']);
        $isGit = isset($spec['git'], $spec['ref'], $spec['commit']);
        if ($to === '' || (!$isFile && !$isGit)) {
            throw new RageKitException("$where: needs url + sha256, or git + ref + commit, and to");
        }
        if (str_starts_with($to, '/') || preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $to) === 1 || preg_match('~^[A-Za-z]:~', $to) === 1) {
            throw new RageKitException("$where: to '$to' must be a path inside the package");
        }
        $target = rtrim($packageDir, '/\\') . '/' . trim($to, '/\\');
        return $isFile
            ? new self($target, url: (string) $spec['url'], sha256: strtolower((string) $spec['sha256']))
            : new self($target, git: (string) $spec['git'], ref: (string) $spec['ref'], commit: strtolower((string) $spec['commit']), path: trim((string) ($spec['path'] ?? ''), '/'));
    }

    /**
     * @param list<array<string, mixed>> $specs
     * @return list<self>
     */
    public static function list(array $specs, string $packageDir, string $file): array
    {
        $list = [];
        foreach (array_values($specs) as $i => $spec) {
            $list[] = self::fromArray((array) $spec, $packageDir, $file, $i);
        }
        return $list;
    }

    public function describe(): string
    {
        return $this->url !== null ? basename($this->target) : basename($this->git ?? '', '.git') . ' ' . $this->ref . ($this->path === '' ? '' : " ($this->path)");
    }
}
