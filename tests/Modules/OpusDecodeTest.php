<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;

/**
 * decode_opus.cc reads voice notes other people sent, so every number in the MP4 is untrusted:
 * damaged copies of the fixture must be refused or decoded, never read out of bounds
 * (tests/fixtures/modules/probe_decode.cc, built with AddressSanitizer).
 */
final class OpusDecodeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testDamagedFilesAreRefusedSafely(): void
    {
        if (trim((string) shell_exec('command -v g++')) === '') {
            self::markTestSkipped('needs g++');
        }
        $root = (string) realpath(self::ROOT);
        $dir = sys_get_temp_dir() . '/rk-opus-' . uniqid();
        mkdir($dir);
        exec("g++ -std=c++17 -g -O1 -fsanitize=address,undefined -fno-sanitize-recover=all -I$root/modules/audio/native"
            . " $root/modules/audio/native/decode_opus.cc $root/tests/fixtures/modules/probe_decode.cc -ldl -o $dir/probe 2>&1", $out, $code);
        if ($code !== 0) {
            self::markTestSkipped("can't build with AddressSanitizer: " . implode("\n", $out));
        }
        $good = (string) file_get_contents("$root/tests/fixtures/modules/opus-voice.mp4");
        $files = [];
        $write = function (string $name, string $bytes) use ($dir, &$files): void {
            file_put_contents("$dir/$name", $bytes);
            $files[] = "$dir/$name";
        };
        // Cut short anywhere.
        for ($len = 0; $len < strlen($good); $len += max(1, intdiv(strlen($good), 97))) {
            $write("cut-$len.mp4", substr($good, 0, $len));
        }
        // Each table's entry count, then every box size inside moov, set to huge values.
        foreach (['stsz' => 8, 'stco' => 4, 'stsc' => 4] as $box => $at) {
            $pos = strpos($good, $box);
            self::assertNotFalse($pos, "the fixture has no $box box");
            foreach (["\xFF\xFF\xFF\xFF", "\x40\x00\x00\x01", "\x00\x00\x00\x00"] as $n => $value) {
                $write("$box-$n.mp4", substr_replace($good, $value, $pos + 4 + $at, 4));
            }
            if ($box === 'stsz') {
                $write('stsz-fixed.mp4', substr_replace($good, "\x00\x00\x00\x01\xFF\xFF\xFF\xFF", $pos + 8, 8));
            }
        }
        $moov = (int) strpos($good, 'moov');
        for ($at = $moov; $at < strlen($good) - 4; $at++) {
            if (preg_match('/^[a-z][a-zA-Z0-9]{3}$/', substr($good, $at + 4, 4)) === 1 && $at > 0) {
                $write("size-$at.mp4", substr_replace($good, "\xFF\xFF\xFF\xF0", $at, 4));
            }
        }
        // Chunk offsets pointing past the end.
        $stco = (int) strpos($good, 'stco');
        $write('offset.mp4', substr_replace($good, "\xFF\xFF\xFF\x00", $stco + 12, 4));
        // Random bytes changed (a fixed seed, so a failure repeats).
        mt_srand(7);
        for ($i = 0; $i < 200; $i++) {
            $bytes = $good;
            for ($k = 0; $k < 8; $k++) {
                $bytes[mt_rand(0, strlen($bytes) - 1)] = chr(mt_rand(0, 255));
            }
            $write("flip-$i.mp4", $bytes);
        }
        $write('good.mp4', $good);
        exec('ASAN_OPTIONS=detect_leaks=0 timeout 120 ' . escapeshellarg("$dir/probe") . ' ' . implode(' ', array_map('escapeshellarg', $files)) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", array_slice($lines, -30)));
        self::assertCount(count($files), $lines);
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^(ok \d+|no .+)$/', $line);
        }
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
