<?php

declare(strict_types=1);

namespace RageKit\Tests\Support;

use RageKit\Cli\Fetch\Fetcher;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Templates\Kit;

/** Makes a kit's or module's `fetch:` dependencies present for a test (false: couldn't, e.g. offline). */
final class Fetched
{
    private const ROOT = __DIR__ . '/../..';

    public static function kit(string $name): bool
    {
        return self::all(Kit::load(self::ROOT . "/kits/$name/kit.yml", (string) realpath(self::ROOT))->fetch);
    }

    public static function module(string $name): bool
    {
        return self::all(Module::load(self::ROOT . "/modules/$name/module.yml", (string) realpath(self::ROOT))->fetch);
    }

    private static function all(array $dependencies): bool
    {
        try {
            foreach ($dependencies as $dependency) {
                Fetcher::ensure($dependency);
            }
            return true;
        } catch (RageKitException) {
            return false;
        }
    }
}
