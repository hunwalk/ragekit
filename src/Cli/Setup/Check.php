<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

/** One line of `ragekit doctor`. */
final class Check
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $status,
        public readonly string $name,
        public readonly string $detail,
    ) {
    }
}
