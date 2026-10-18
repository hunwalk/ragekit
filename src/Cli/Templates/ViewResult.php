<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/** Output of a ViewGenerator: generated PHP to compile into the app, and a progress summary. */
final class ViewResult
{
    /** @param list<string> $phpSources */
    public function __construct(public readonly array $phpSources, public readonly string $summary)
    {
    }
}
