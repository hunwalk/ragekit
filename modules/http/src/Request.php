<?php

namespace RageKit\Http;

use RageKit\App;
use RageKit\Events;
use RageKit\Modules;

/** An HTTP request: set it up, then send() it. Made by Http::get(), post() or request(). */
final class Request
{
    /** @var list<string> "Name: value" */
    private array $headers = [];
    private string $body = '';
    private string $file = '';
    private float $timeout = 30.0;
    /** @var list<\Closure> fn(int $received, int $total) */
    private array $progress = [];
    private int $id = 0;
    private bool $cancelled = false;

    public function __construct(private string $method, private string $url)
    {
    }

    /** @throws \InvalidArgumentException when the name or value contains a line break */
    public function header(string $name, string $value): Request
    {
        if (strpbrk($name . $value, "\r\n") !== false || $name === '' || str_contains($name, ':')) {
            throw new \InvalidArgumentException("invalid HTTP header: $name");
        }
        $this->headers[] = $name . ': ' . $value;
        return $this;
    }

    public function body(string $body): Request
    {
        $this->body = $body;
        return $this;
    }

    /** Sends $form as the body, with its multipart/form-data Content-Type. */
    public function multipart(Multipart $form): Request
    {
        $this->body = $form->body();
        return $this->header('Content-Type', $form->contentType());
    }

    /** Gives up after this many seconds without progress (connecting, or no data arriving). Default 30. */
    public function timeout(float $seconds): Request
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Writes the body to a file instead of Response::$body (relative paths: the app's folder;
     * missing folders are created). The file appears only if the answer is a 2xx status.
     */
    public function saveTo(string $path): Request
    {
        $this->file = App::resolve($path);
        return $this;
    }

    /** About 10 times a second while data arrives: bytes so far, and the total (-1 if unknown). */
    public function onProgress(\Closure $fn): Request
    {
        $this->progress[] = $fn;
        return $this;
    }

    /**
     * Starts the request. $then(Response $r) runs once, on the UI thread, with the answer or the
     * error (see Response::ok()). Returns this request, e.g. to cancel() it. Without the http
     * module's library next to the app, $then runs right away with that as the error.
     */
    public function send(\Closure $then): Request
    {
        if ($this->id !== 0 || $this->cancelled) {
            throw new \LogicException('this request was already sent');
        }
        if (!Modules::available('http')) {
            $this->cancelled = true; // nothing to send again or cancel
            $then(Response::failed('http module not available: ' . \ragekit_module_error('http')));
            return $this;
        }
        $id = \http_request($this->method, $this->url, implode("\n", $this->headers), $this->body, $this->file, $this->timeout);
        $this->id = $id;
        Events::on('http', $id, 'done', function (string $data) use ($id, $then): void {
            Events::off('http', $id);
            $then(Response::parse($data));
        });
        Events::on('http', $id, 'error', function (string $data) use ($id, $then): void {
            Events::off('http', $id);
            $then(Response::failed($data));
        });
        foreach ($this->progress as $fn) {
            Events::on('http', $id, 'progress', function (string $data) use ($fn): void {
                $parts = explode(' ', $data);
                $fn((int) $parts[0], (int) ($parts[1] ?? '-1'));
            });
        }
        return $this;
    }

    /** Stops the request; its callbacks won't run. Harmless when it already finished. */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        if ($this->id !== 0) {
            \http_cancel($this->id);
            Events::off('http', $this->id);
        }
    }
}
