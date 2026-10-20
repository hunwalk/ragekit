<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** Output of SlintCompiler. */
final class CompiledViews
{
    /**
     * @param list<ScreenModel>     $screens
     * @param array<string, string> $libraries Slint library name => path, for slint-compiler -L
     * @param list<string>          $imports   kit entry files app.slint imports
     * @param list<string>          $images    image files embedded via @image-url (absolute)
     * @param list<string>          $warnings  hints for the author, e.g. old template syntax
     */
    public function __construct(
        public readonly string $slint,
        public readonly array $screens,
        public readonly array $libraries,
        public readonly array $imports = [],
        public readonly array $images = [],
        public readonly array $warnings = [],
    ) {
    }
}
