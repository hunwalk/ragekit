<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Http\Http;
use RageKit\Http\Multipart;
use RageKit\Http\Response;
use RageKit\Tests\Slint\Runtime\FakeHost;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';
require_once __DIR__ . '/../../modules/http/src/Http.php';
require_once __DIR__ . '/../../modules/http/src/Request.php';
require_once __DIR__ . '/../../modules/http/src/Response.php';
require_once __DIR__ . '/../../modules/http/src/Multipart.php';

/** Multipart bodies, checked by what PHP's own parser makes of them (a php -S server). */
final class MultipartTest extends TestCase
{
    public function testPhpParsesFieldsAndFiles(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . random_bytes(2000) . "\r\n--not-the-boundary\r\n";
        $form = (new Multipart())
            ->field('title', 'Hello "world" ✓')
            ->file('payload', 'payload.json', '{"text":"hi"}', 'application/json')
            ->file('images[]', 'a.png', $png, 'image/png')
            ->file('images[]', 'b "quoted".bin', "\0\1\2");
        $answer = $this->post($form);

        self::assertStringStartsWith('multipart/form-data; boundary=', $answer['contentType']);
        self::assertSame(['title' => 'Hello "world" ✓'], $answer['fields']);
        self::assertSame([
            ['field' => 'payload', 'name' => 'payload.json', 'type' => 'application/json', 'error' => 0, 'sha256' => hash('sha256', '{"text":"hi"}')],
            ['field' => 'images', 'name' => 'a.png', 'type' => 'image/png', 'error' => 0, 'sha256' => hash('sha256', $png)],
            ['field' => 'images', 'name' => 'b %22quoted%22.bin', 'type' => 'application/octet-stream', 'error' => 0, 'sha256' => hash('sha256', "\0\1\2")],
        ], $answer['files']);
    }

    public function testEmptyForm(): void
    {
        $form = new Multipart();
        self::assertSame('--' . $form->boundary() . "--\r\n", $form->body());
    }

    public function testNamesCantBreakTheHeaders(): void
    {
        $form = (new Multipart())->file("x\r\nInjected: 1", "f\r\n.txt", 'data');
        self::assertStringNotContainsString("\r\nInjected", $form->body());
        self::assertStringContainsString('name="x%0D%0AInjected: 1"; filename="f%0D%0A.txt"', $form->body());
    }

    public function testRequestSendsItWithItsContentType(): void
    {
        FakeHost::reset();
        FakeHost::$modules['http'] = '';
        $form = (new Multipart())->field('a', 'b');
        Http::post('https://example.com/up', '')->multipart($form)->send(function (Response $r): void {
        });
        self::assertSame($form->body(), FakeHost::$http[0]['body']);
        self::assertSame('Content-Type: multipart/form-data; boundary=' . $form->boundary(), FakeHost::$http[0]['headers']);
    }

    /** @return array{contentType: string, fields: array<string, string>, files: list<array<string, mixed>>} */
    private function post(Multipart $form): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/../fixtures/http/multipart.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => 'Content-Type: ' . $form->contentType(),
                'content' => $form->body(),
            ]]);
            $json = (string) file_get_contents("http://127.0.0.1:$port/", false, $context);
        } finally {
            proc_terminate($server, 9);
            proc_close($server);
        }
        $answer = json_decode($json, true);
        self::assertIsArray($answer, $json);
        return $answer;
    }
}
