<?php

declare(strict_types=1);

namespace RageKit\Cli\Composer;

use RageKit\Cli\Setup\BuildTools;

/**
 * Composer script callbacks for apps, e.g. the starter's composer.json:
 *
 *     "post-create-project-cmd": ["RageKit\\Cli\\Composer\\Hooks::offerBuildTools", "@php vendor/bin/ragekit setup"]
 *
 * They run inside Composer and ask through its console: commands Composer starts can't read the
 * keyboard on Windows.
 */
final class Hooks
{
    /** On Windows without the C++ build tools: offers to install them. Elsewhere does nothing. */
    public static function offerBuildTools(object $event): void
    {
        if (!BuildTools::missing()) {
            return;
        }
        $io = $event->getIO(); // Composer\IO\IOInterface
        BuildTools::offer(
            $io->isInteractive() ? fn(string $question): bool => (bool) $io->askConfirmation($question, true) : null,
            fn(): int => BuildTools::install(),
            fn(string $line) => $io->write($line),
        );
        putenv(BuildTools::OFFERED . '=1');
    }
}
