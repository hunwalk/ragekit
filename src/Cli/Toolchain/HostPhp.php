<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

use RageKit\Cli\RageKitException;

/**
 * The host PHP's embed library (libphp), which normal builds on Linux and macOS link. Without it
 * tpc's php-builder compiles a private PHP once (~10 minutes); with it a build takes seconds.
 *
 * tpc links it by itself only when it's named libphp.so (or .a) and, outside an interactive
 * terminal, only when PHPX was already built against it. Debian and Ubuntu's libphp8.4-embed
 * installs libphp8.4.so instead, and php-config doesn't mention it. So for tpc RageKit prepares
 * a PHP_HOME (`<toolchain>/host-php-8.4`, links to php, php-config and the library as
 * lib/libphp.so) and builds PHPX's libphpx.so against it with cmake (~10 s, once).
 */
final class HostPhp
{
    private const STAMP = '.ragekit-host-php';

    private function __construct(
        public readonly string $library,
        private readonly string $phpConfig,
    ) {
    }

    /**
     * The host PHP if builds can link it (nothing in missing()); else null. Not on Windows
     * (TypePHP's release ships PHP there), nor when PHP_HOME points tpc elsewhere.
     *
     * @param array<string, string>|null $env defaults to the process environment
     */
    public static function find(?array $env = null): ?self
    {
        $env ??= getenv();
        if (Platform::isWindows() || ($env['PHP_HOME'] ?? '') !== '' || self::missing($env) !== []) {
            return null;
        }
        return new self((string) self::library($env), self::phpConfig());
    }

    /**
     * What linking the host PHP still needs, as Debian/Ubuntu package names: the embed library,
     * php-config, cmake, and GMP's and MPFR's headers (PHPX uses them).
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function missing(array $env): array
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $missing = [];
        if (self::library($env) === null) {
            $missing[] = "libphp$version-embed";
        }
        if (self::phpConfig() === '') {
            $missing[] = "php$version-dev";
        }
        if (trim((string) shell_exec('command -v cmake')) === '') {
            $missing[] = 'cmake';
        }
        foreach (['gmpxx.h' => 'libgmp-dev', 'mpfr.h' => 'libmpfr-dev'] as $header => $package) {
            if (!self::hasHeader($header)) {
                $missing[] = $package;
            }
        }
        return $missing;
    }

    private static function phpConfig(): string
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return trim((string) shell_exec("command -v php-config$version || command -v php-config"));
    }

    /** Whether the C++ compiler finds $header (asks it once per header). */
    private static function hasHeader(string $header): bool
    {
        static $found = [];
        if (!isset($found[$header])) {
            $command = 'printf ' . escapeshellarg("#include <$header>\n") . ' | c++ -fsyntax-only -x c++ - >/dev/null 2>&1';
            exec($command, $output, $code);
            $found[$header] = $code === 0;
        }
        return $found[$header];
    }

    /**
     * The embed library: `RAGEKIT_LIBPHP` if set (`none`: don't use one), else libphp.so / .a / .dylib or a versioned
     * libphp8.4.so in php-config's prefix and the system library folders.
     *
     * @param array<string, string> $env
     * @param list<string>|null     $dirs defaults to the folders above
     */
    public static function library(array $env, ?array $dirs = null): ?string
    {
        $override = trim($env['RAGEKIT_LIBPHP'] ?? '');
        if ($override === 'none') {
            return null;
        }
        if ($override !== '') {
            if (!is_file($override)) {
                throw new RageKitException("RAGEKIT_LIBPHP: no such file: $override");
            }
            return $override;
        }
        if ($dirs === null) {
            $prefix = trim((string) shell_exec('php-config --prefix 2>/dev/null')) ?: '/usr';
            $dirs = array_unique(["$prefix/lib", "$prefix/lib64", '/usr/lib', '/usr/lib/' . php_uname('m') . '-linux-gnu']);
        }
        $names = ['libphp.so', 'libphp.a', 'libphp.dylib', 'libphp' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.so'];
        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                if (is_file("$dir/$name")) {
                    return "$dir/$name";
                }
            }
        }
        return null;
    }

    /** Whether the library is linked into the executable (a static libphp.a) rather than shipped next to it. */
    public function isStatic(): bool
    {
        return str_ends_with($this->library, '.a');
    }

    /** The PHP_HOME tpc and PHPX's cmake get: a folder of links, kept in the toolchain folder. */
    public function home(string $toolchainDir): string
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $home = "$toolchainDir/host-php-$version";
        $extension = $this->isStatic() ? 'a' : (str_ends_with($this->library, '.dylib') ? 'dylib' : 'so');
        self::link("$home/bin/php", PHP_BINARY);
        self::link("$home/bin/php-config", $this->phpConfig);
        self::link("$home/bin/php-config$version", $this->phpConfig);
        self::link("$home/lib/libphp.$extension", $this->library);
        return $home;
    }

    /**
     * Builds libphpx.so in $phpxDir/lib against this PHP unless it already was (a stamp records
     * which PHP). $run runs a command and returns its exit code.
     *
     * @param callable(list<string>): int $run
     * @return bool whether it was built now
     */
    public function buildPhpx(string $phpxDir, string $home, callable $run): bool
    {
        $stamp = "$phpxDir/lib/" . self::STAMP;
        $expected = (realpath($this->library) ?: $this->library) . ' ' . PHP_VERSION . "\n";
        if (is_file("$phpxDir/lib/libphpx.so") && @file_get_contents($stamp) === $expected) {
            return false;
        }
        $buildDir = "$home/phpx-build";
        $configure = ['cmake', '-S', $phpxDir, '-B', $buildDir, '-DCMAKE_BUILD_TYPE=Release', '-DBUILD_TESTS=OFF', "-Dphp_dir=$home"];
        $jobs = max(1, min(8, (int) trim((string) shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'))));
        $build = ['cmake', '--build', $buildDir, '--parallel', (string) $jobs, '--target', 'phpx'];
        if ($run($configure) !== 0 || $run($build) !== 0 || !is_file("$phpxDir/lib/libphpx.so")) {
            throw new RageKitException("building libphpx.so against the host PHP failed (or set RAGEKIT_LIBPHP=none to compile a private PHP instead)");
        }
        file_put_contents($stamp, $expected);
        return true;
    }

    private static function link(string $link, string $target): void
    {
        if (@readlink($link) === $target) {
            return;
        }
        @mkdir(dirname($link), 0777, true);
        @unlink($link);
        if (!symlink($target, $link)) {
            throw new RageKitException("could not create $link");
        }
    }
}
