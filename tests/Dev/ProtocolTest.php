<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Slint\Dev\Protocol;

require_once __DIR__ . '/../../src/Slint/Dev/Protocol.php';

final class ProtocolTest extends TestCase
{
    private const TRICKY = ['set-string', '1', 'p3', "a\tb\nc\\d é✓ \r end \\t", ''];

    public function testRoundTrip(): void
    {
        $line = Protocol::encode(...self::TRICKY);
        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        self::assertSame(self::TRICKY, Protocol::decode($line));
    }

    public function testCppSideMatches(): void
    {
        if (trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('g++ missing');
        }
        $dir = sys_get_temp_dir() . '/rk-proto-' . uniqid();
        mkdir($dir);
        $root = dirname(__DIR__, 2);
        exec('g++ -std=c++20 ' . escapeshellarg("$root/tests/fixtures/devshell/protocol_probe.cc") . ' ' . escapeshellarg("$root/native/slint/devshell/protocol.cc")
            . ' -I' . escapeshellarg("$root/native/slint/devshell") . ' -o ' . escapeshellarg("$dir/probe") . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $line = Protocol::encode(...self::TRICKY);
        $result = (string) shell_exec('printf %s ' . escapeshellarg($line) . ' | ' . escapeshellarg("$dir/probe"));
        self::assertSame($line, $result);
        self::assertSame(self::TRICKY, Protocol::decode($result));
    }
}
