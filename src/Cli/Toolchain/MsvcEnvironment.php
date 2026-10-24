<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

use RageKit\Cli\RageKitException;

/** Windows: the environment Visual Studio's vcvars64.bat sets up for cl.exe. */
final class MsvcEnvironment
{
    /**
     * $env plus MSVC's variables. Unchanged when cl.exe is already on PATH (a developer
     * prompt).
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    public static function apply(array $env): array
    {
        if (trim((string) shell_exec('where cl.exe 2>nul')) !== '') {
            return $env;
        }
        $installer = getenv('ProgramFiles(x86)') . '\\Microsoft Visual Studio\\Installer';
        $vswhere = $installer . '\\vswhere.exe';
        if (!is_file($vswhere)) {
            throw new RageKitException('Visual Studio Build Tools not found (needs the "Desktop development with C++" workload); ' . self::installHint());
        }
        $vsPath = trim((string) shell_exec('"' . $vswhere . '" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath'));
        if ($vsPath === '') {
            throw new RageKitException('Visual Studio found, but without the MSVC x64 C++ tools; ' . self::installHint());
        }
        $vcvars = $vsPath . '\\VC\\Auxiliary\\Build\\vcvars64.bat';
        // vcvars64 calls vswhere itself and expects it on PATH.
        putenv('PATH=' . $installer . ';' . getenv('PATH'));
        $env = self::merge($env, (string) shell_exec('cmd /s /c ""' . $vcvars . '" >nul && set"'));
        $pathKey = isset($env['Path']) ? 'Path' : 'PATH';
        $env[$pathKey] = $installer . ';' . ($env[$pathKey] ?? '');
        return $env;
    }

    /**
     * $env with the variables of `set`'s output. Names are case-insensitive on Windows: `set` may
     * print PATH where $env has Path (after a putenv('PATH=…')), and the old one must not stay.
     *
     * @internal public for tests
     * @param array<string, string> $env
     * @return array<string, string>
     */
    public static function merge(array $env, string $setOutput): array
    {
        foreach (preg_split('/\r?\n/', $setOutput) as $line) {
            if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
                foreach (array_keys($env) as $name) {
                    if (strcasecmp((string) $name, $m[1]) === 0) {
                        unset($env[$name]);
                    }
                }
                $env[$m[1]] = $m[2];
            }
        }
        return $env;
    }

    /** RageKit's install-build-tools.ps1, wherever RageKit is (e.g. vendor\\ragekit\\ragekit). */
    public static function installScript(): string
    {
        return str_replace('/', '\\', dirname(__DIR__, 3)) . '\\install-build-tools.ps1';
    }

    /** The command that installs the Build Tools. */
    public static function installCommand(): string
    {
        return 'powershell -ExecutionPolicy Bypass -File "' . self::installScript() . '"';
    }

    /** For error messages. */
    public static function installHint(): string
    {
        return 'to install them: ' . self::installCommand();
    }
}
