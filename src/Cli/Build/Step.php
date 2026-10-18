<?php

declare(strict_types=1);

namespace RageKit\Cli\Build;

/** One stage of a build. Throws RageKitException on problems the user can fix. */
interface Step
{
    /** Short label for the progress line, e.g. "Copy assets". */
    public function name(): string;

    /**
     * Does the work and returns a one-line summary for the progress line, e.g. "6 files"
     * (console formatting tags like <comment> are allowed).
     */
    public function run(BuildContext $context): string;
}
