<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

/** What DevViews wrote for the dev shell. */
final class DevOutput
{
    /**
     * @param array<string, string> $entries     screen short name => absolute entry .slint file
     * @param string                $screensJson absolute path of screens.json
     * @param list<string>          $watch       files whose change means "regenerate and reload"
     * @param bool                  $changed     whether what the shell loads changed (app.slint, kit copies, entries)
     */
    public function __construct(
        public readonly array $entries,
        public readonly string $screensJson,
        public readonly array $watch,
        public readonly bool $changed = true,
        /** @var list<string> hints for the author, e.g. old template syntax */
        public readonly array $warnings = [],
    ) {
    }
}
