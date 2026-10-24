<?php

declare(strict_types=1);

namespace RageKit\Tests\Support;

use PHPUnit\Framework\Assert;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Setup\ToolchainDir;
use RageKit\Cli\Toolchain\Platform;
use RageKit\Cli\Toolchain\Toolchain;

/**
 * The toolchain `bin/ragekit setup` installed for this checkout: found like the CLI finds it (a
 * `.ragekit/` in the root, else the per-user one for these pins). SLINT_HOME / TYPEPHP_HOME win.
 */
final class InstalledToolchain
{
    public static function root(): string
    {
        return (string) realpath(__DIR__ . '/../..');
    }

    public static function dir(): string
    {
        return ToolchainDir::find(self::root(), Pins::forRageKit(self::root()));
    }

    public static function slintHome(): string
    {
        return getenv('SLINT_HOME') ?: self::dir() . '/slint';
    }

    public static function toolchain(): Toolchain
    {
        return Toolchain::detect(self::dir(), Pins::forRageKit(self::root()));
    }

    /** Skips the test unless the dev shell builds here: Linux or Windows, a C++ compiler and Slint's C++ package. */
    public static function skipWithoutDevShell(): void
    {
        if (Platform::os() === 'macos' || !is_file(self::slintHome() . '/include/slint/slint-interpreter.h')) {
            Assert::markTestSkipped('needs Linux or Windows and the Slint C++ package');
        }
        try {
            self::toolchain();
        } catch (RageKitException $e) {
            Assert::markTestSkipped('needs a C++ compiler: ' . $e->getMessage());
        }
    }
}
