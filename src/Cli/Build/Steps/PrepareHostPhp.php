<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\Toolchain\HostPhp;
use RageKit\Cli\Toolchain\Platform;
use RageKit\Cli\Toolchain\TypePhp;

/**
 * The PHP a normal build links (see HostPhp): the host's libphp when there is a usable one, else
 * the private PHP tpc's php-builder compiles (MergeConfig chose). For the host's, prepares tpc's
 * PHP_HOME and libphpx.so, and puts libphp.so and libphpx.so next to the executable, which finds
 * them through its $ORIGIN rpath. On Windows, a TypePHP checkout links PHPX from the release
 * (PHP_HOME) that `ragekit setup` installs next to it, and the release's PHP DLLs are copied next
 * to the executable on every build (Windows loads DLLs from the exe's folder first, so a copy left
 * from an older TypePHP would otherwise win).
 */
final class PrepareHostPhp implements Step
{
    public function name(): string
    {
        return 'Prepare PHP';
    }

    public function run(BuildContext $context): string
    {
        if ($context->nano()) {
            return 'not needed (Nano)';
        }
        if (isset($context->config['php-builder'])) {
            return 'private PHP (tpc php-builder)';
        }
        if (Platform::isWindows()) {
            return self::windows($context);
        }
        $host = HostPhp::find();
        $phpxDir = $context->toolchain->phpxDir();
        if ($host === null || $phpxDir === null) {
            return 'as tpc finds it';
        }
        $home = $host->home($context->toolchain->toolchainDir());
        $built = $host->buildPhpx($phpxDir, $home, fn(array $command) => $context->exec(
            $command,
            $home,
            'building libphpx.so for PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' (once)',
        ));
        $context->toolchain->usePhpHome($home);

        $copied = ['libphpx.so'];
        Paths::copyRecursive("$phpxDir/lib/libphpx.so", $context->buildDir() . '/libphpx.so');
        if (!$host->isStatic()) {
            // Named libphp.so inside (its soname) whatever the file is called, e.g. Debian's libphp8.4.so.
            Paths::copyRecursive($host->library, $context->buildDir() . '/libphp.so');
            array_unshift($copied, 'libphp.so');
        }
        return basename($host->library) . ' → ' . implode(', ', $copied) . ($built ? ' (libphpx.so built)' : '');
    }

    private static function windows(BuildContext $context): string
    {
        $phpHome = $context->toolchain->env('PHP_HOME');
        if ($context->toolchain->phpxDir() === null || $phpHome === null) {
            return 'as tpc finds it';
        }
        $phpx = TypePhp::releasePhpx($phpHome);
        if ($phpx === null) {
            return "as tpc finds it (no TypePHP release with PHPX in $phpHome)";
        }
        $context->toolchain->usePhpxHome($phpx);
        $copied = self::copyWindowsPhpDlls($phpHome, $context->buildDir());
        return "PHP and PHPX from $phpHome → " . ($copied === [] ? 'no DLLs found' : implode(', ', $copied));
    }

    /** The DLLs a normal build's exe loads: PHP, PHPX and their dependencies. */
    private const WINDOWS_DLLS = ['php8ts.dll', 'phpx.dll', 'libmpdec-*.dll', 'libmpdec++-*.dll', 'gmp-*.dll', 'mpfr-*.dll'];

    /**
     * Copies the release's PHP DLLs into $buildDir, replacing older copies.
     *
     * @return list<string> the files copied, sorted
     */
    public static function copyWindowsPhpDlls(string $phpHome, string $buildDir): array
    {
        $copied = [];
        foreach (self::WINDOWS_DLLS as $pattern) {
            foreach (glob("$phpHome/$pattern") ?: [] as $file) {
                Paths::copyRecursive($file, "$buildDir/" . basename($file));
                $copied[] = basename($file);
            }
        }
        $copied = array_values(array_unique($copied));
        sort($copied);
        return $copied;
    }
}
