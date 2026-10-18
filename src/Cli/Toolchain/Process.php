<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

use RageKit\Cli\RageKitException;

/** Runs build tools with the toolchain's environment. */
final class Process
{
    /** @param array<string, string> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function withEnv(string $name, string $value): self
    {
        return new self([...$this->env, $name => $value]);
    }

    /** Streams the tool's output; returns its exit code. */
    public function run(array $command, string $cwd): int
    {
        $process = proc_open($this->resolve($command), [STDIN, STDOUT, STDERR], $pipes, $cwd, $this->env);
        if (!is_resource($process)) {
            throw new RageKitException('could not start ' . $command[0]);
        }
        return proc_close($process);
    }

    /**
     * Collects stdout + stderr. $tick, if given, is called with the whole seconds elapsed each
     * time another second passes (not on Windows, where proc_open pipes can't be polled).
     *
     * @param (callable(int): void)|null $tick
     * @return array{int, string} exit code, output
     */
    public function capture(array $command, string $cwd, ?callable $tick = null): array
    {
        $process = proc_open($this->resolve($command), [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $this->env);
        if (!is_resource($process)) {
            throw new RageKitException('could not start ' . $command[0]);
        }
        $output = $tick === null || Platform::isWindows()
            ? (string) stream_get_contents($pipes[1])
            : self::readTicking($pipes[1], $tick);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    /**
     * @param resource            $pipe
     * @param callable(int): void $tick
     */
    private static function readTicking($pipe, callable $tick): string
    {
        stream_set_blocking($pipe, false);
        $start = hrtime(true);
        $output = '';
        $reported = 0;
        while (!feof($pipe)) {
            $read = [$pipe];
            $none = null;
            if ((int) @stream_select($read, $none, $none, 0, 200_000) > 0) {
                $output .= (string) fread($pipe, 65536);
            }
            $seconds = intdiv(hrtime(true) - $start, 1_000_000_000);
            if ($seconds > $reported) {
                $reported = $seconds;
                $tick($seconds);
            }
        }
        return $output;
    }

    /**
     * Windows looks a bare program name (cl.exe) up on ragekit's own PATH, not on the PATH of the
     * environment passed to proc_open(), so find it on the toolchain's PATH first.
     */
    private function resolve(array $command): array
    {
        if (!Platform::isWindows() || strpbrk($command[0], '/\\') !== false) {
            return $command;
        }
        foreach (explode(';', $this->env['Path'] ?? $this->env['PATH'] ?? '') as $dir) {
            if ($dir !== '' && is_file($dir . '\\' . $command[0])) {
                $command[0] = $dir . '\\' . $command[0];
                break;
            }
        }
        return $command;
    }
}
