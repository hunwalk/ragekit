<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

use RageKit\Cli\RageKitException;

/**
 * The running dev shell (native/slint/devshell): ragekit sends it commands on its stdin
 * (reload, restart-php, reload-module, stop) and reads reload outcomes from a loopback connection
 * the shell opens to it (--status), which starts with the token from RAGEKIT_DEV_STATUS_TOKEN.
 * A socket rather than an extra pipe: Windows passes a child only stdin/stdout/stderr, and
 * stream_select() there works on sockets only.
 */
final class ShellProcess
{
    /** @var resource|null the shell's connection, once it proved itself */
    private $status = null;
    private string $buffer = '';

    /**
     * @param resource $process
     * @param resource $control  the shell's stdin
     * @param resource $listener where the shell connects for status lines
     */
    private function __construct(private $process, private $control, private $listener, private string $token)
    {
    }

    /**
     * @param list<string>          $command the shell and its arguments
     * @param array<string, string> $env
     */
    public static function start(array $command, array $env, string $cwd): self
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error)
            ?: throw new RageKitException("could not listen on 127.0.0.1 for the dev shell: $error");
        $address = (string) stream_socket_get_name($listener, false);
        $token = bin2hex(random_bytes(16));
        $command = [$command[0], '--status', $address, ...array_slice($command, 1)];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $cwd, ['RAGEKIT_DEV_STATUS_TOKEN' => $token] + $env)
            ?: throw new RageKitException('could not start ' . $command[0]);
        return new self($process, $pipes[0], $listener, $token);
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
        while (microtime(true) < $end) {
            $newline = strpos($this->buffer, "\n");
            if ($newline !== false && $this->status !== null) {
                $line = substr($this->buffer, 0, $newline);
                $this->buffer = substr($this->buffer, $newline + 1);
                return rtrim($line, "\r");
            }
            $read = [$this->status ?? $this->listener];
            $none = null;
            if ((int) @stream_select($read, $none, $none, 0, 100_000) === 0) {
                continue;
            }
            if ($this->status === null) {
                $this->accept();
                continue;
            }
            $chunk = fread($this->status, 4096);
            if ($chunk === false || ($chunk === '' && feof($this->status))) {
                return null;
            }
            $this->buffer .= $chunk;
        }
        return null;
    }

    public function stop(): void
    {
        $this->send('stop');
        fclose($this->control);
        proc_close($this->process);
        if ($this->status !== null) {
            fclose($this->status);
        }
        fclose($this->listener);
    }

    /** Takes a waiting connection if its first line is the token (anything else is dropped). */
    private function accept(): void
    {
        $connection = @stream_socket_accept($this->listener, 0);
        if ($connection === false) {
            return;
        }
        // Into $buffer, not fgets(): bytes after the token must stay where readStatus() looks.
        $buffer = '';
        $end = microtime(true) + 5;
        while (!str_contains($buffer, "\n") && microtime(true) < $end) {
            $read = [$connection];
            $none = null;
            if ((int) @stream_select($read, $none, $none, 0, 100_000) > 0) {
                $chunk = fread($connection, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer .= $chunk;
            }
        }
        $newline = strpos($buffer, "\n");
        if ($newline === false || rtrim(substr($buffer, 0, $newline), "\r") !== $this->token) {
            fclose($connection);
            return;
        }
        $this->status = $connection;
        $this->buffer = substr($buffer, $newline + 1);
    }
}
