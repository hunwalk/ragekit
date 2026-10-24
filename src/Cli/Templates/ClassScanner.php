<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\RageKitException;

/**
 * Reads the app's Component classes with reflection. Runs introspect.php in a separate PHP
 * process, so loading app code can't clash with the CLI's own classes or kill the build.
 */
final class ClassScanner
{
    /**
     * @param list<string> $files      app PHP files
     * @param list<string> $sourceDirs the app's source folders: a class in one without template()
     *                                 uses templates/<its path there>.rk
     * @return array<string, ClassInfo> by class name
     */
    public static function scan(array $files, string $rageKitRoot, string $projectDir, array $sourceDirs = []): array
    {
        $input = (string) tempnam(sys_get_temp_dir(), 'rk-introspect');
        file_put_contents($input, json_encode(['rageKitRoot' => $rageKitRoot, 'projectDir' => $projectDir, 'files' => $files, 'sourceDirs' => $sourceDirs], JSON_THROW_ON_ERROR));
        // stderr goes to a temp file: stream_select() on proc_open pipes doesn't work on Windows,
        // and a file can't fill up and block the child like a second pipe could.
        $errFile = (string) tempnam(sys_get_temp_dir(), 'rk-introspect-err');
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/introspect.php', $input],
                [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new RageKitException('could not start ' . PHP_BINARY);
            }
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
            $stderr = (string) @file_get_contents($errFile);
        } finally {
            @unlink($input);
            @unlink($errFile);
        }
        if ($code !== 0) {
            throw new RageKitException("reading the app's component classes failed:\n" . self::failure($stderr, $stdout));
        }
        $rows = json_decode($stdout, true);
        if (!is_array($rows)) {
            throw new RageKitException("reading the app's component classes failed: unexpected output: " . substr(trim($stdout . $stderr), 0, 200));
        }
        $classes = [];
        foreach ($rows as $row) {
            $info = ClassInfo::fromArray($row);
            $classes[$info->name] = $info;
        }
        ksort($classes);
        return $classes;
    }

    /**
     * The first "Fatal error" / "Parse error" line (it carries file:line), else the trimmed output.
     *
     * @internal
     */
    public static function failure(string $stderr, string $stdout): string
    {
        $all = trim($stderr . "\n" . $stdout);
        foreach (preg_split('/\R/', $all) ?: [] as $line) {
            if (preg_match('/(Fatal error|Parse error)/', $line) === 1) {
                return trim($line);
            }
        }
        return $all;
    }
}
