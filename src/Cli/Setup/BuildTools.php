<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\MsvcEnvironment;
use RageKit\Cli\Toolchain\Platform;

/**
 * Windows: Visual Studio's C++ build tools, which builds need but `ragekit setup` can't install
 * like the rest of the toolchain (they need administrator rights). Offered by `ragekit setup` and
 * by the starter's Composer hook; installed by install-build-tools.ps1.
 */
final class BuildTools
{
    /** Set by the Composer hook once it has asked, so `ragekit setup` right after doesn't ask again. */
    public const OFFERED = 'RAGEKIT_BUILD_TOOLS_OFFERED';

    /** Windows without MSVC's x64 C++ compiler. */
    public static function missing(): bool
    {
        if (!Platform::isWindows()) {
            return false;
        }
        try {
            MsvcEnvironment::apply(getenv());
            return false;
        } catch (RageKitException) {
            return true;
        }
    }

    /** Runs install-build-tools.ps1 in this console; returns its exit code. */
    public static function install(): int
    {
        $process = proc_open(
            ['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', MsvcEnvironment::installScript()],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        return is_resource($process) ? proc_close($process) : 1;
    }

    /**
     * Explains what's missing and, when someone can answer ($ask isn't null), offers to install.
     *
     * @param (callable(string): bool)|null $ask     asks a yes/no question, default yes
     * @param callable(): int               $install runs the installer, returns its exit code
     * @param callable(string): void        $say
     * @return bool whether they were installed
     */
    public static function offer(?callable $ask, callable $install, callable $say): bool
    {
        $say('The C++ build tools (Visual Studio 2022 Build Tools) are missing: RageKit needs them to build apps on Windows.');
        $later = 'To install them later: ' . MsvcEnvironment::installCommand();
        if ($ask === null) {
            $say($later);
            return false;
        }
        if (!$ask('Install them now? Windows asks for administrator rights; a few GB, 5-20 minutes. [Y/n] ')) {
            $say($later);
            return false;
        }
        $code = $install();
        if ($code !== 0) {
            $say("Installing them failed (exit code $code). $later");
            return false;
        }
        return true;
    }
}
