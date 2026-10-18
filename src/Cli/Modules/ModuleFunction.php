<?php

declare(strict_types=1);

namespace RageKit\Cli\Modules;

/** One native function a module declares in module.yml. */
final class ModuleFunction
{
    /**
     * @param array<string, string> $params  name => int|float|bool|string, in order
     * @param string|null           $returns int|float|bool|string, or null for nothing
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly ?string $returns,
    ) {
    }
}
