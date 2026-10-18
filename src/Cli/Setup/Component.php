<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

use RageKit\Cli\RageKitException;

/** One pinned part of the toolchain (TypePHP, Slint), as toolchain.yml declares it for this platform. */
final class Component
{
    /**
     * @param list<string>                                                  $patches   absolute paths
     * @param list<array{url: string, sha256: string, to: string, strip: int}> $downloads
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $git,
        public readonly ?string $commit,
        public readonly ?string $composerLock,
        public readonly array $patches,
        public readonly array $downloads,
        public readonly string $version,
    ) {
    }

    /** @param array<string, mixed> $spec already overlaid for this platform */
    public static function fromArray(string $name, array $spec, string $pinsDir): self
    {
        $path = fn(string $relative) => $pinsDir . '/' . $relative;
        $downloads = [];
        foreach ($spec['downloads'] ?? [] as $i => $download) {
            if (!isset($download['url'], $download['sha256'])) {
                throw new RageKitException("toolchain.yml: $name downloads[$i] needs url and sha256");
            }
            $downloads[] = [
                'url' => (string) $download['url'],
                'sha256' => strtolower((string) $download['sha256']),
                'to' => trim((string) ($download['to'] ?? ''), '/\\'),
                'strip' => (int) ($download['strip'] ?? 0),
            ];
        }
        if (isset($spec['git']) !== isset($spec['commit'])) {
            throw new RageKitException("toolchain.yml: $name needs both git and commit");
        }
        return new self(
            $name,
            isset($spec['git']) ? (string) $spec['git'] : null,
            isset($spec['commit']) ? strtolower((string) $spec['commit']) : null,
            isset($spec['composer-lock']) ? $path((string) $spec['composer-lock']) : null,
            array_map(fn($patch) => $path((string) $patch), array_values($spec['patches'] ?? [])),
            $downloads,
            (string) ($spec['version'] ?? $spec['commit'] ?? ''),
        );
    }

    /** Whether there is anything to install on this platform. */
    public function available(): bool
    {
        return $this->git !== null || $this->downloads !== [];
    }

    /** Identifies exactly what gets installed, including the patches' and lock file's content. */
    public function fingerprint(): string
    {
        $files = [];
        foreach ([...$this->patches, ...($this->composerLock === null ? [] : [$this->composerLock])] as $file) {
            $files[basename($file)] = is_file($file) ? hash_file('sha256', $file) : 'missing';
        }
        return hash('sha256', (string) json_encode([$this->git, $this->commit, $this->downloads, $files]));
    }
}
