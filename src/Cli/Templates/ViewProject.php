<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\Kits\KitRegistry;

/** Input of a ViewGenerator. */
final class ViewProject
{
    /**
     * @param array<string, ClassInfo> $classes
     * @param list<string>              $modules native modules the app enables (ragekit: modules:)
     * @param string                    $icon    the app's icon (ragekit: icon:), absolute; '' = none
     */
    public function __construct(
        public readonly array $classes,
        public readonly KitRegistry $kits,
        public readonly string $activeKit,
        public readonly string $outDir,
        public readonly string $projectDir = '',
        public readonly array $modules = [],
        public readonly string $icon = '',
    ) {
    }
}
