<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/** Input of a ViewGenerator. */
final class ViewProject
{
    /** @param array<string, ClassInfo> $classes */
    public function __construct(
        public readonly array $classes,
        public readonly KitRegistry $kits,
        public readonly string $activeKit,
        public readonly string $outDir,
        public readonly string $projectDir = '',
    ) {
    }
}
