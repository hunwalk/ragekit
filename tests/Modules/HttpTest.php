<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Events;
use RageKit\Http\Http;
use RageKit\Http\Response;
use RageKit\Tests\Slint\Runtime\FakeHost;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';
require_once __DIR__ . '/../../modules/http/src/Http.php';
require_once __DIR__ . '/../../modules/http/src/Request.php';
require_once __DIR__ . '/../../modules/http/src/Response.php';

/** The http module's PHP API over fake \http_* functions (the library: HttpLibraryTest). */
final class HttpTest extends TestCase
{
    protected function setUp(): void
    {
        FakeHost::reset();
        FakeHost::$modules['http'] = '';
        for ($id = 1; $id <= 5; $id++) {
            Events::off('http', $id); // handlers of an earlier test's requests
        }
    }

    public function testGetSendsWithDefaults(): void
    {
        Http::get('https://example.com/a')->send(function (Response $r): void {
        });
        self::assertSame([['method' => 'GET', 'url' => 'https://example.com/a', 'headers' => '', 'body' => '', 'file' => '', 'timeout' => 30.0]], FakeHost::$http);
    }

    public function testRequestOptions(): void
    {
        Http::post('https://example.com/b', '{"x":1}')
            ->header('Content-Type', 'application/json')
            ->header('X-Two', 'y')
            ->timeout(5)
            ->send(function (Response $r): void {
            });
        Http::request('DELETE', 'https://example.com/c')->saveTo('/tmp/out.bin')->send(function (Response $r): void {
        });
        self::assertSame("Content-Type: application/json\nX-Two: y", FakeHost::$http[0]['headers']);
        self::assertSame(['POST', '{"x":1}', 5.0], [FakeHost::$http[0]['method'], FakeHost::$http[0]['body'], FakeHost::$http[0]['timeout']]);
        self::assertSame(['DELETE', '/tmp/out.bin'], [FakeHost::$http[1]['method'], FakeHost::$http[1]['file']]);
    }

    public function testRelativeDownloadPathsAreInTheAppFolder(): void
    {
        Http::get('https://example.com/a')->saveTo('cache/a.jpg')->send(function (Response $r): void {
        });
        self::assertSame(getcwd() . '/cache/a.jpg', FakeHost::$http[0]['file']);
    }

    public function testHeaderNamesAndValuesCantSmuggleLines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Http::get('https://example.com/a')->header('X-A', "b\nX-Evil: 1");
    }

    public function testDoneBecomesAResponse(): void
    {
        $got = null;
        Http::get('https://example.com/a')->send(function (Response $r) use (&$got): void {
            $got = $r;
        });
        Events::deliver('http', 1, 'done', "200\nContent-Type: text/plain\nSet-Cookie: a=1\nset-cookie: b=2\nX-Empty:\n\nbody\n\nwith\0bytes");
        self::assertInstanceOf(Response::class, $got);
        self::assertSame(200, $got->status);
        self::assertTrue($got->ok());
        self::assertSame('', $got->error);
        self::assertSame("body\n\nwith\0bytes", $got->body);
        self::assertSame('text/plain', $got->header('content-type'));
        self::assertSame('text/plain', $got->header('Content-Type'));
        self::assertSame('a=1, b=2', $got->header('Set-Cookie'), 'repeated headers are joined');
        self::assertSame('', $got->header('X-Empty'));
        self::assertSame('', $got->header('X-Missing'));
        self::assertSame(['content-type' => 'text/plain', 'set-cookie' => 'a=1, b=2', 'x-empty' => ''], $got->headers);
    }

    public function testAnErrorStatusIsNotOk(): void
    {
        $got = null;
        Http::get('https://example.com/a')->send(function (Response $r) use (&$got): void {
            $got = $r;
        });
        Events::deliver('http', 1, 'done', "404\n\nnot here");
        self::assertSame(404, $got->status);
        self::assertFalse($got->ok());
        self::assertSame('not here', $got->body);
    }

    public function testAnErrorEventBecomesAResponseWithError(): void
    {
        $got = null;
        Http::get('https://example.com/a')->send(function (Response $r) use (&$got): void {
            $got = $r;
        });
        Events::deliver('http', 1, 'error', 'Could not resolve host: example.com');
        self::assertSame(0, $got->status);
        self::assertFalse($got->ok());
        self::assertSame('Could not resolve host: example.com', $got->error);
        self::assertSame([], $got->headers);
    }

    public function testProgressAndOnlyOneOutcome(): void
    {
        $log = [];
        Http::get('https://example.com/a')
            ->onProgress(function (int $received, int $total) use (&$log): void {
                $log[] = "progress $received/$total";
            })
            ->send(function (Response $r) use (&$log): void {
                $log[] = "done $r->status";
            });
        Events::deliver('http', 1, 'progress', '100 -1');
        Events::deliver('http', 1, 'progress', '300 1000');
        Events::deliver('http', 1, 'done', "200\n\n");
        Events::deliver('http', 1, 'done', "200\n\n");
        Events::deliver('http', 1, 'progress', '500 1000');
        self::assertSame(['progress 100/-1', 'progress 300/1000', 'done 200'], $log);
    }

    public function testCancelStopsTheRequestAndItsCallbacks(): void
    {
        $called = false;
        $request = Http::get('https://example.com/a')->send(function (Response $r) use (&$called): void {
            $called = true;
        });
        $request->cancel();
        $request->cancel();
        Events::deliver('http', 1, 'done', "200\n\n");
        self::assertFalse($called);
        self::assertSame(['cancel' => 1], FakeHost::$http[1]);
        self::assertCount(2, FakeHost::$http, 'cancelling twice calls the library once');
    }

    public function testCancelBeforeSendIsHarmless(): void
    {
        Http::get('https://example.com/a')->cancel();
        self::assertSame([], FakeHost::$http);
    }

    public function testSendingTwiceIsAnError(): void
    {
        $request = Http::get('https://example.com/a');
        $request->send(function (Response $r): void {
        });
        $this->expectException(\LogicException::class);
        $request->send(function (Response $r): void {
        });
    }

    public function testWithoutTheModuleTheCallbackGetsAnError(): void
    {
        FakeHost::$modules['http'] = 'not found: /app/librk_module_http.so';
        $got = null;
        Http::get('https://example.com/a')->send(function (Response $r) use (&$got): void {
            $got = $r;
        });
        self::assertFalse($got->ok());
        self::assertSame('http module not available: not found: /app/librk_module_http.so', $got->error);
        self::assertSame([], FakeHost::$http);
    }
}
