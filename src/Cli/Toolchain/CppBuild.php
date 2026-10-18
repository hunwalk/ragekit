<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** What to compile: a shared library or a program from C++ sources. */
final class CppBuild
{
    /**
     * @param string       $name         output name without prefix/extension (e.g. ragekit_slint)
     * @param string       $dir          output folder
     * @param list<string> $sources
     * @param list<string> $includePaths
     * @param list<string> $linkPaths
     * @param list<string> $linkLibs     library names without prefix/extension
     * @param string       $std          e.g. c++17
     * @param list<string> $defines      e.g. SLINT_FEATURE_EXPERIMENTAL
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly array $sources,
        public readonly array $includePaths = [],
        public readonly array $linkPaths = [],
        public readonly array $linkLibs = [],
        public readonly string $std = 'c++17',
        public readonly array $defines = [],
    ) {
    }
}
