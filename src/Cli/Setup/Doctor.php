<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\MsvcEnvironment;
use RageKit\Cli\Toolchain\Platform;
use RageKit\Cli\Toolchain\HostPhp;

/** `ragekit doctor`: what a build needs, whether it's there, and how to fix what isn't. */
final class Doctor
{
    public function __construct(
        private readonly Pins $pins,
        private readonly string $toolchainDir,
        private readonly string $projectDir,
    ) {
    }

    /** @return list<Check> */
    public function checks(): array
    {
        return [
            $this->php(),
            $this->component('TypePHP', 'typephp', 'TYPEPHP_HOME', fn(string $home) => is_file("$home/bin/tpc.php") || is_file("$home/tpc") || is_file("$home/tpc.exe")),
            $this->component('Slint', 'slint', 'SLINT_HOME', fn(string $home) => is_file("$home/bin/slint-compiler") || is_file("$home/bin/slint-compiler.exe")),
            $this->compiler(),
            ...$this->setupTools(),
            ...$this->hostPhp(),
            $this->project(),
        ];
    }

    private function php(): Check
    {
        if (PHP_VERSION_ID < 80400 && getenv('TYPEPHP_HOME') === false) {
            return new Check(Check::FAIL, 'PHP', PHP_VERSION . ': TypePHP needs PHP 8.4+ to run (ragekit itself runs on 8.2+)');
        }
        return new Check(Check::OK, 'PHP', PHP_VERSION . ' (' . PHP_BINARY . ')');
    }

    /** @param callable(string): bool $usable whether an overriding folder looks like the real thing */
    private function component(string $label, string $name, string $variable, callable $usable): Check
    {
        $override = getenv($variable);
        if ($override !== false && $override !== '') {
            return $usable($override)
                ? new Check(Check::WARN, $label, "$variable=$override (used as is, not checked against RageKit's pins)")
                : new Check(Check::FAIL, $label, "$variable=$override doesn't look like $label: fix or unset it");
        }
        $component = $this->pins->component($name);
        if (!$component->available()) {
            return new Check(Check::FAIL, $label, 'RageKit has no pinned version for ' . Platform::key() . ": set $variable");
        }
        $dir = "$this->toolchainDir/$name";
        if (Installer::installed($component, $dir)) {
            $patches = count($component->patches);
            return new Check(Check::OK, $label, $this->version($component) . ($patches > 0 ? " + $patches patch" . ($patches > 1 ? 'es' : '') : '') . " ($dir)");
        }
        return is_dir($dir)
            ? new Check(Check::FAIL, $label, "$dir is from another RageKit version or unfinished: run `ragekit setup` again")
            : new Check(Check::FAIL, $label, "not set up: run `ragekit setup` (installs into $this->toolchainDir)");
    }

    private function version(ToolchainComponent $component): string
    {
        return $component->git !== null && $component->version === $component->commit ? substr($component->version, 0, 8) : $component->version;
    }

    private function compiler(): Check
    {
        if (Platform::isWindows()) {
            try {
                MsvcEnvironment::apply(getenv());
                return new Check(Check::OK, 'C++ compiler', 'MSVC (Visual Studio Build Tools)');
            } catch (RageKitException $e) {
                return new Check(Check::FAIL, 'C++ compiler', $e->getMessage());
            }
        }
        $version = strtok((string) shell_exec('g++ --version 2>/dev/null') ?: (string) shell_exec('c++ --version 2>/dev/null'), "\n");
        return $version !== false && $version !== ''
            ? new Check(Check::OK, 'C++ compiler', $version)
            : new Check(Check::FAIL, 'C++ compiler', 'no g++ / c++ on PATH (Debian/Ubuntu: apt install build-essential)');
    }

    /** @return list<Check> the tools `ragekit setup` runs */
    private function setupTools(): array
    {
        $tools = ['git' => 'git', 'composer' => 'Composer', 'tar' => 'tar'];
        if (Platform::isWindows()) {
            $tools['7z'] = '7-Zip (unpacks Slint)';
        }
        $checks = [];
        foreach ($tools as $command => $label) {
            $found = Platform::onPath($command);
            $checks[] = new Check($found !== null ? Check::OK : Check::WARN, $label, $found ?? "not on PATH (only `ragekit setup` needs it)");
        }
        return $checks;
    }

    /** @return list<Check> */
    private function hostPhp(): array
    {
        if (Platform::isWindows()) {
            return [];
        }
        if (getenv('PHP_HOME') !== false) {
            return [new Check(Check::OK, 'libphp', 'PHP_HOME is set: tpc uses the PHP there')];
        }
        $host = HostPhp::find();
        if ($host !== null) {
            return [new Check(Check::OK, 'libphp', "$host->library: normal builds link it")];
        }
        $missing = implode(' ', HostPhp::missing(getenv()));
        return [new Check(Check::WARN, 'libphp', "not usable: the first normal build compiles PHP once (~10 min, ~/.typephp). To skip that, install $missing (Debian/Ubuntu). Nano builds don't need it")];
    }

    private function project(): Check
    {
        return is_file("$this->projectDir/project.yml")
            ? new Check(Check::OK, 'Project', "$this->projectDir/project.yml")
            : new Check(Check::WARN, 'Project', "no project.yml in $this->projectDir (run ragekit build in an app's folder)");
    }
}
