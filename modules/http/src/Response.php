<?php

namespace RageKit\Http;

/** The answer to a Request, or why there is none ($error). */
final class Response
{
    /**
     * @param int                   $status  0 when there is no answer
     * @param array<string, string> $headers lowercase names; repeated headers joined with ", "
     * @param string                $body    empty for downloads (Request::saveTo())
     * @param string                $error   '' when the server answered; else why not (no network, timeout, …)
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $error,
    ) {
    }

    /** The server answered with a 2xx status. */
    public function ok(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    /** A header's value ('' when missing); the name is case-insensitive. */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** From the library's "done" event: status, header lines, an empty line, the body. */
    public static function parse(string $data): Response
    {
        $end = strpos($data, "\n\n");
        if ($end === false) {
            return new Response(0, [], '', 'malformed answer from the http module');
        }
        $lines = explode("\n", substr($data, 0, $end));
        $status = (int) $lines[0];
        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            $colon = strpos($lines[$i], ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($lines[$i], 0, $colon)));
            $value = trim(substr($lines[$i], $colon + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }
        return new Response($status, $headers, substr($data, $end + 2), '');
    }

    public static function failed(string $error): Response
    {
        return new Response(0, [], '', $error);
    }
}
