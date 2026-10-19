<?php

namespace RageKit\Http;

/**
 * HTTP requests that never block the window (http module): the request runs on a worker thread
 * and its callback runs on the UI thread when the answer is in, like a click handler.
 *
 *     Http::get('https://api.example.com/items')
 *         ->header('Accept', 'application/json')
 *         ->send(function (Response $r): void {
 *             $this->status = $r->ok() ? $r->body : ($r->error ?: "HTTP $r->status");
 *         });
 */
final class Http
{
    public static function get(string $url): Request
    {
        return new Request('GET', $url);
    }

    public static function post(string $url, string $body): Request
    {
        return (new Request('POST', $url))->body($body);
    }

    /** Any method: PUT, PATCH, DELETE, HEAD, … */
    public static function request(string $method, string $url): Request
    {
        return new Request(strtoupper($method), $url);
    }
}
