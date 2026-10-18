<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** A compiler invocation and what it produces. */
final class CompileCommand
{
    /**
     * @param list<string> $command the full command line (run without a shell)
     * @param string       $output  the file it produces
     * @param string       $link    what an app links against: the library itself, or its
     *                              import .lib on Windows
     */
    public function __construct(
        public readonly array $command,
        public readonly string $output,
        public readonly string $link,
    ) {
    }
}
