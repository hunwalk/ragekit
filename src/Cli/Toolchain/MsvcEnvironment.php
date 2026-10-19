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
        $output = (string) shell_exec('cmd /s /c ""' . $vcvars . '" >nul && set"');
        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = $m[2];
            }
        }
        $pathKey = isset($env['Path']) ? 'Path' : 'PATH';
        $env[$pathKey] = $installer . ';' . ($env[$pathKey] ?? '');
        return $env;
    }

    /** How to install the Build Tools: RageKit's install-build-tools.ps1, wherever RageKit is. */
    public static function installHint(): string
    {
        $script = str_replace('/', '\\', dirname(__DIR__, 3)) . '\\install-build-tools.ps1';
        return "to install them: powershell -ExecutionPolicy Bypass -File \"$script\"";
    }
}
