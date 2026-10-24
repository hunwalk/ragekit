<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

/** The link to the dev shell: messages out, replies and events in. */
final class Channel
{
    /** @var list<list<string>> messages that arrived while waiting for a reply */
    private array $queue = [];

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(private $in, private $out)
    {
    }

    /**
     * The shell's loopback connection: it passes the address (RAGEKIT_DEV_CHANNEL) and the token
     * to send first (RAGEKIT_DEV_TOKEN) in the environment (native/slint/devshell/child.h).
     */
    public static function connect(): self
    {
        $address = (string) getenv('RAGEKIT_DEV_CHANNEL');
        $context = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $socket = @stream_socket_client("tcp://$address", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("could not reach the dev shell at '$address': $error");
        }
        // Reads wait for the user, not default_socket_timeout (60 s, after which fgets() fails).
        stream_set_timeout($socket, 86400 * 365);
        fwrite($socket, getenv('RAGEKIT_DEV_TOKEN') . "\n");
        return new self($socket, $socket);
    }

    public function send(string ...$fields): void
    {
        fwrite($this->out, Protocol::encode(...$fields));
        fflush($this->out);
    }

    /** @return list<string> the reply (`opened …`, `result …`, `error …`); events arriving first are kept for next() */
    public function request(string ...$fields): array
    {
        $this->send(...$fields);
        while (($line = fgets($this->in)) !== false) {
            $message = Protocol::decode($line);
            if (in_array($message[0], ['opened', 'result', 'error'], true)) {
                return $message;
            }
            $this->queue[] = $message;
        }
        throw new \RuntimeException('the dev shell went away');
    }

    /** @return list<string>|null the next message; null once the shell closed the pipe */
    public function next(): ?array
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        $line = fgets($this->in);
        return $line === false ? null : Protocol::decode($line);
    }
}
