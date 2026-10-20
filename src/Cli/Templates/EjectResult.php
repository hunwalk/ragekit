<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/** What `ragekit eject` did. */
final class EjectResult
{
    /**
     * @param array<string, string> $renamed   old component name => new one
     * @param string|null           $gitignore a line to add to the app's .gitignore (fetched code), if any
     */
    public function __construct(
        public readonly string $kit,
        public readonly string $name,
        public readonly string $dir,
        public readonly array $renamed,
        public readonly bool $used,
        public readonly ?string $gitignore,
        public readonly string $fromPrefix = '',
        public readonly string $toPrefix = '',
    ) {
    }
}
