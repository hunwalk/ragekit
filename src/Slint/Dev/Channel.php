<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

/** The pipe pair to the dev shell (fds 3 and 4): messages out, replies and events in. */
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
