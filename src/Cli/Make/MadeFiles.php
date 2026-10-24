<?php

declare(strict_types=1);

namespace RageKit\Cli\Make;

/** What a make: command did: files created and changed (project-relative) and how to use them. */
final class MadeFiles
{
    /**
     * @param list<string> $created
     * @param list<string> $changed
     * @param list<string> $hints
     */
    public function __construct(public readonly array $created, public readonly array $changed = [], public readonly array $hints = [])
    {
    }
}
