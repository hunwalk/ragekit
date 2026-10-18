<?php

declare(strict_types=1);

namespace RageKit\Cli\Build;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Toolchain\CompileCommand;

/**
 * Runs a compiler command unless its output is up to date. What it was built from is kept next to
 * the output ($output.cmd): the command (flags, sources, paths) and a hash of each input file's
 * content, so any change rebuilds, even one made in the same second as the last build.
 */
final class NativeBuild
{
    /**
     * @param list<string> $sources a source's folder (headers, generated app.h) counts as input too
     * @param string       $status  shown while it builds (e.g. "building the dev shell")
     * @return bool whether it was built (false: already up to date)
     * @throws RageKitException with $failure and the compiler's output when the build fails
     */
    public static function ensure(BuildContext $context, CompileCommand $build, array $sources, string $status, string $failure): bool
    {
        $stamp = self::stamp($build->command, $sources);
        if (self::upToDate($build->output, $stamp)) {
            return false;
        }
        @mkdir(dirname($build->output), 0777, true);
        @unlink($build->output . '.cmd');
        if ($context->exec($build->command, dirname($build->output), $status) !== 0) {
            throw new RageKitException($failure . ($context->capturedOutput === '' ? '' : ":\n" . rtrim($context->capturedOutput)));
        }
        file_put_contents($build->output . '.cmd', $stamp);
        return true;
    }

    /**
     * @param list<string> $command
     * @param list<string> $sources every file in a source's folder is an input, except *.php (the
     *                              generated binder doesn't affect the C++ library)
     */
    public static function stamp(array $command, array $sources): string
    {
        $inputs = [];
        foreach ($sources as $source) {
            foreach (glob(dirname($source) . '/*') ?: [] as $file) {
                if (is_file($file) && !str_ends_with($file, '.php')) {
                    $inputs[$file] = sha1_file($file);
                }
            }
        }
        ksort($inputs);
        $lines = $command;
        foreach ($inputs as $file => $hash) {
            $lines[] = "$hash $file";
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param string $stamp what the output must have been built from (see stamp()) */
    public static function upToDate(string $output, string $stamp): bool
    {
        return is_file($output) && @file_get_contents($output . '.cmd') === $stamp;
    }
}
