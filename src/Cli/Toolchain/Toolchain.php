<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Pins;

/**
 * Everything the build steps need from the machine: TypePHP (TypePhp, found when first needed,
 * so `ragekit dev` works without it), the C++ compiler (Gcc / Msvc), and running tools with the
 * right environment (Process; on Windows that includes MSVC's, see MsvcEnvironment).
 */
final class Toolchain
{
    private ?TypePhp $typePhp = null;

    private function __construct(
        private readonly string $toolchainDir,
        private readonly Pins $pins,
        private readonly CppCompiler $compiler,
        private Process $process,
    ) {
    }

    /** @param string $toolchainDir where `ragekit setup` installs (see Setup\ToolchainDir) */
    public static function detect(string $toolchainDir, Pins $pins): self
    {
        if (!Platform::isWindows()) {
            if (trim((string) shell_exec('command -v g++ || command -v c++')) === '') {
                throw new RageKitException('no C++ compiler on PATH (install build-essential)');
            }
            return new self($toolchainDir, $pins, new Gcc(), new Process(getenv()));
        }
        return new self($toolchainDir, $pins, new Msvc(), new Process(self::windowsEnvironment($toolchainDir)));
    }

    /**
     * MSVC's environment plus TypePHP's on Windows. A source checkout still takes PHP's import
     * libraries and DLLs from a release (PHP_HOME; `ragekit setup` puts one in
     * .ragekit/typephp/release), except in Nano builds.
     *
     * @return array<string, string>
     */
    private static function windowsEnvironment(string $toolchainDir): array
    {
        $env = MsvcEnvironment::apply(getenv());
        $typePhpHome = rtrim(getenv('TYPEPHP_HOME') ?: "$toolchainDir/typephp", '\\/');
        $isRelease = is_file("$typePhpHome\\tpc.exe");
        $phpHome = $isRelease ? $typePhpHome : (getenv('PHP_HOME') ?: "$typePhpHome\\release");
        $pathKey = isset($env['Path']) ? 'Path' : 'PATH';
        $env[$pathKey] = $phpHome . ';' . ($env[$pathKey] ?? '');
        $env['TYPEPHP_HOME'] = $typePhpHome;
        $env['PHP_HOME'] = $phpHome;
        if ($isRelease) {
            $env['PHPX_HOME'] = $typePhpHome . '\\phpx';
            $env['PHPRC'] = $typePhpHome . '\\php.ini';
        } else {
            unset($env['PHPX_HOME'], $env['PHPRC']); // the checkout's vendor/swoole/phpx
        }
        return $env;
    }

    /** @return list<string> the command that starts tpc (arguments follow) */
    public function tpc(): array
    {
        $this->typePhp ??= TypePhp::locate($this->toolchainDir, $this->pins);
        return $this->typePhp->command;
    }

    /** TypePHP's PHPX source tree (see TypePhp::phpxDir). */
    public function phpxDir(): ?string
    {
        $this->tpc();
        return $this->typePhp->phpxDir();
    }

    /** Points tpc at a PHP installation (PHP_HOME) from now on. */
    public function usePhpHome(string $home): void
    {
        $this->process = $this->process->withEnv('PHP_HOME', $home);
    }

    public function toolchainDir(): string
    {
        return $this->toolchainDir;
    }

    /** @return array<string, mixed> tpc settings this platform needs unless the app sets them */
    public function defaults(bool $nano = false): array
    {
        return TypePhp::defaults($nano);
    }

    public function compiler(): CppCompiler
    {
        return $this->compiler;
    }

    /** The executable tpc produces for output path $base (no extension). */
    public function executable(string $base): string
    {
        return Platform::isWindows() ? str_replace('/', '\\', $base) . '.exe' : $base;
    }

    public function run(array $command, string $cwd): int
    {
        return $this->process->run($command, $cwd);
    }

    /**
     * @param (callable(int): void)|null $tick
     * @return array{int, string}
     */
    public function capture(array $command, string $cwd, ?callable $tick = null): array
    {
        return $this->process->capture($command, $cwd, $tick);
    }
}
