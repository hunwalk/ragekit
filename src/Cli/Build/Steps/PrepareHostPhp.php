<?php

declare(strict_types=1);

namespace RageKit\Cli\Build\Steps;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Step;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\Toolchain\HostPhp;

/**
 * The PHP a normal build links (see HostPhp): the host's libphp when there is a usable one, else
 * the private PHP tpc's php-builder compiles (MergeConfig chose). For the host's, prepares tpc's
 * PHP_HOME and libphpx.so, and puts libphp.so and libphpx.so next to the executable, which finds
 * them through its $ORIGIN rpath.
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
}
