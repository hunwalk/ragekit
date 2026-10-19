<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;

/**
 * The http module's library (libcurl), driven by tests/fixtures/modules/probe_http.cc against a
 * local `php -S` running tests/fixtures/http/router.php. Never touches the internet.
 */
final class HttpLibraryTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var array<string, array{kind: string, data: string, progress: int}>|null */
    private static ?array $results = null;
    /** @var array<string, string> */
    private static array $checks = [];
    private static string $dir = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== '') {
            exec('rm -rf ' . escapeshellarg(self::$dir));
        }
    }

    /** @return array{kind: string, data: string, progress: int} */
    private function outcome(string $name): array
    {
        $this->probe();
        self::assertArrayHasKey($name, self::$results, "no result for $name");
        return self::$results[$name];
    }

    private function check(string $name): string
    {
        $this->probe();
        self::assertArrayHasKey($name, self::$checks, "no check $name");
        return self::$checks[$name];
    }

    /** @return array{status: int, headers: list<string>, body: string} */
    private function done(string $name): array
    {
        $result = $this->outcome($name);
        self::assertSame('done', $result['kind'], "$name: " . $result['data']);
        $end = strpos($result['data'], "\n\n");
        self::assertIsInt($end);
        $head = explode("\n", substr($result['data'], 0, $end));
        return ['status' => (int) array_shift($head), 'headers' => $head, 'body' => substr($result['data'], $end + 2)];
    }

    private function probe(): void
    {
        if (self::$results !== null) {
            return;
        }
        if (trim((string) shell_exec('command -v g++')) === '' || !is_file('/usr/include/curl/curl.h') && glob('/usr/include/*/curl/curl.h') === []) {
            self::markTestSkipped('needs g++ and libcurl\'s headers (libcurl4-openssl-dev)');
        }
        $dir = self::$dir = sys_get_temp_dir() . '/rk-http-' . uniqid();
        mkdir("$dir/gen", 0777, true);
        $root = (string) realpath(self::ROOT);
        $module = Module::load("$root/modules/http/module.yml", $root);
        file_put_contents("$dir/gen/rk_module_http.h", ModuleEmitter::header($module));
        file_put_contents("$dir/gen/rk_module_http_call.cc", ModuleEmitter::callEntry($module));
        $this->sh("g++ -std=c++17 -O1 -Wall -Werror -Wno-unused-function -shared -fPIC -fvisibility=hidden $root/modules/http/native/*.cc $dir/gen/rk_module_http_call.cc"
            . " -I$dir/gen -I$root/native/include -I$root/modules/http/native -lcurl -lpthread -o $dir/librk_module_http.so");
        $this->sh("g++ -std=c++17 $root/tests/fixtures/modules/probe_http.cc -ldl -lpthread -o $dir/probe");

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", "$root/tests/fixtures/http/router.php"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/server.log", 'w'], 2 => ['file', "$dir/server.log", 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '8'] + getenv(),
        );
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }
            exec('timeout 60 ' . escapeshellarg("$dir/probe") . ' ' . escapeshellarg("$dir/librk_module_http.so")
                . ' ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg("$dir/downloads") . ' 2>&1', $lines, $code);
        } finally {
            proc_terminate($server, 9);
            proc_close($server);
        }
        self::assertSame(0, $code, implode("\n", $lines));

        self::$results = [];
        foreach ($lines as $line) {
            $parts = explode(' ', $line, 4);
            if ($parts[0] === 'result') {
                $data = preg_replace_callback('/\\\\(.)/', fn($m) => ['0' => "\0", 'n' => "\n", 't' => "\t", '\\' => '\\'][$m[1]], $parts[3] ?? '');
                self::$results[$parts[1]] = ['kind' => $parts[2], 'data' => (string) $data, 'progress' => 0];
            } elseif ($parts[0] === 'progress') {
                self::$results[$parts[1]]['progress'] = (int) $parts[2];
            } elseif ($parts[0] === 'check') {
                self::$checks[$parts[1]] = $parts[2];
            }
        }
    }

    public function testGetGivesStatusHeadersAndBody(): void
    {
        $hello = $this->done('hello');
        self::assertSame(200, $hello['status']);
        self::assertContains('X-Test: one', $hello['headers']);
        self::assertSame('Hello, world', $hello['body']);
    }

    public function testBodiesAreBinarySafe(): void
    {
        self::assertSame("a\0b\nc\td\\e", $this->done('binary')['body']);
    }

    public function testAnErrorStatusIsAResponseNotAnError(): void
    {
        $missing = $this->done('missing');
        self::assertSame(404, $missing['status']);
        self::assertSame('not here', $missing['body']);
    }

    public function testRedirectsAreFollowedAndOnlyTheFinalHeadersKept(): void
    {
        $redirect = $this->done('redirect');
        self::assertSame(200, $redirect['status']);
        self::assertSame('Hello, world', $redirect['body']);
        self::assertSame([], array_filter($redirect['headers'], fn($h) => stripos($h, 'Location:') === 0));
    }

    public function testMethodsHeadersAndBodiesAreSent(): void
    {
        self::assertSame("POST\nyes\na=1\0b", $this->done('post')['body']);
        self::assertSame("PUT\n-\nput body", $this->done('put')['body']);
        self::assertSame("DELETE\n-\n", $this->done('delete')['body']);
    }

    public function testNetworkFailuresAreErrorEvents(): void
    {
        self::assertSame('error', $this->outcome('unreachable')['kind']);
        self::assertNotSame('', $this->outcome('unreachable')['data']);
        self::assertSame('error', $this->outcome('bad-url')['kind']);
    }

    public function testParallelRequestsEachGetTheirOwnAnswer(): void
    {
        self::assertSame('Hello, world', $this->done('parallel-a')['body']);
        self::assertSame("a\0b\nc\td\\e", $this->done('parallel-b')['body']);
        self::assertSame('Hello, world', $this->done('parallel-c')['body']);
    }

    public function testTimeoutIsTimeWithoutProgress(): void
    {
        self::assertSame('error', $this->outcome('timeout')['kind']);
        self::assertStringContainsString('timed out', $this->outcome('timeout')['data']);
        self::assertLessThan(3000, (int) $this->check('timeout-ms'), 'a 1 s timeout on a 5 s answer');
    }

    public function testLargeBodyReportsProgress(): void
    {
        self::assertSame(2 * 1024 * 1024, strlen($this->done('big')['body']));
        self::assertGreaterThan(2, $this->outcome('big')['progress']);
    }

    public function testDownloadGoesToTheFileNotTheEvent(): void
    {
        $download = $this->done('download');
        self::assertSame(200, $download['status']);
        self::assertSame('', $download['body']);
        self::assertGreaterThan(2, $this->outcome('download')['progress']);
        $file = self::$dir . '/downloads/sub dir/big é.bin';
        self::assertSame(2 * 1024 * 1024, filesize($file), 'missing folders are created, UTF-8 names work');
        self::assertFileDoesNotExist("$file.part");
    }

    public function testAFailedDownloadLeavesNoFile(): void
    {
        self::assertSame(404, $this->done('download-missing')['status']);
        self::assertFileDoesNotExist(self::$dir . '/downloads/missing.bin');
        self::assertFileDoesNotExist(self::$dir . '/downloads/missing.bin.part');
        self::assertSame('error', $this->outcome('download-unwritable')['kind']);
        self::assertStringContainsString("can't write", $this->outcome('download-unwritable')['data']);
    }

    public function testCancelIsQuickAndSilent(): void
    {
        self::assertLessThan(100, (int) $this->check('cancel-ms'));
        self::assertSame('none', $this->outcome('cancelled')['kind']);
    }

    public function testResetCancelsEverythingAndRequestsWorkAfterwards(): void
    {
        self::assertLessThan(1500, (int) $this->check('reset-ms'));
        self::assertSame('none', $this->outcome('after-reset')['kind']);
        self::assertSame('Hello, world', $this->done('again')['body']);
    }

    public function testNothingArrivesAfterDoneOrError(): void
    {
        self::assertSame('0', $this->check('events-after-end'));
        self::assertSame('1', $this->check('done'));
    }

    private function sh(string $command): void
    {
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));
    }
}
