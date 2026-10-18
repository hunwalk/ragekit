<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

use RageKit\Cli\RageKitException;

/**
 * The running dev shell (native/slint/devshell): ragekit sends it commands on its stdin
 * (reload, restart-php, reload-module, stop) and reads reload outcomes from fd 3.
 */
final class ShellProcess
{
    /**
     * @param resource $process
     * @param resource $control the shell's stdin
     * @param resource $status  fd 3: "reloaded" / "reload-failed" lines
     */
    private function __construct(private $process, private $control, private $status)
    {
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    public static function start(array $command, array $env, string $cwd): self
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR, 3 => ['pipe', 'w']], $pipes, $cwd, $env)
            ?: throw new RageKitException('could not start ' . $command[0]);
        return new self($process, $pipes[0], $pipes[3]);
    }

    /** null while the shell runs, else its exit code. */
    public function exitCode(): ?int
    {
        $status = proc_get_status($this->process);
        return $status['running'] ? null : $status['exitcode'];
    }

    public function send(string $command): void
    {
        @fwrite($this->control, "$command\n");
        @fflush($this->control);
    }

    /** The next status line, or null after $timeout seconds or when the shell is gone. */
    public function readStatus(float $timeout): ?string
    {
        $end = microtime(true) + $timeout;
        $line = '';
        while (microtime(true) < $end) {
            $read = [$this->status];
            $none = null;
            if ((int) @stream_select($read, $none, $none, 0, 100_000) > 0) {
                $chunk = fgets($this->status);
                if ($chunk === false) {
                    return null;
                }
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    return rtrim($line);
                }
            }
        }
        return null;
    }

    public function stop(): void
    {
        $this->send('stop');
        fclose($this->control);
        proc_close($this->process);
    }
}
