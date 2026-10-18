<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;

/** The audio module's library on miniaudio's null device, driven by tests/fixtures/modules/probe_audio.cc. */
final class AudioLibraryTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var list<string>|null */
    private static ?array $lines = null;

    /** A mono 16-bit WAV of a 440 Hz tone. */
    public static function wav(string $file, float $seconds, int $rate = 22050): void
    {
        $frames = (int) round($seconds * $rate);
        $data = '';
        for ($i = 0; $i < $frames; $i++) {
            $data .= pack('v', (int) round(sin(2 * M_PI * 440 * $i / $rate) * 12000) & 0xFFFF);
        }
        file_put_contents($file, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data);
    }

    /** @return list<string> */
    private function lines(): array
    {
        if (self::$lines !== null) {
            return self::$lines;
        }
        if (trim((string) shell_exec('command -v g++')) === '' || !\RageKit\Tests\Support\Fetched::module('audio')) {
            self::markTestSkipped('needs g++ and miniaudio (fetched from the audio module\'s fetch:)');
        }
        $dir = sys_get_temp_dir() . '/rk-audio-' . uniqid();
        mkdir("$dir/gen", 0777, true);
        $module = Module::load(self::ROOT . '/modules/audio/module.yml', (string) realpath(self::ROOT));
        file_put_contents("$dir/gen/rk_module_audio.h", ModuleEmitter::header($module));
        file_put_contents("$dir/gen/rk_module_audio_call.cc", ModuleEmitter::callEntry($module));
        $wav = "$dir/my tone é.wav";
        self::wav($wav, 0.5);
        $root = (string) realpath(self::ROOT);
        $this->sh("g++ -std=c++17 -O1 -shared -fPIC -fvisibility=hidden $root/modules/audio/native/audio.cc $dir/gen/rk_module_audio_call.cc"
            . " -I$dir/gen -I$root/native/include -I$root/modules/audio/native -I$root/modules/audio/native/third_party/miniaudio -ldl -lpthread -lm -o $dir/librk_module_audio.so");
        $this->sh("g++ -std=c++17 $root/tests/fixtures/modules/probe_audio.cc -ldl -lpthread -o $dir/probe");
        exec('RAGEKIT_AUDIO_NULL=1 timeout 30 ' . escapeshellarg("$dir/probe") . ' ' . escapeshellarg("$dir/librk_module_audio.so") . ' ' . escapeshellarg($wav) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        return self::$lines = $lines;
    }

    private function value(string $name): string
    {
        foreach ($this->lines() as $line) {
            if (str_starts_with($line, "$name ")) {
                return substr($line, strlen($name) + 1);
            }
        }
        self::fail("no '$name' line in:\n" . implode("\n", $this->lines()));
    }

    /** @return list<string> event lines between two marks */
    private function eventsBetween(string $from, string $to): array
    {
        $lines = $this->lines();
        $start = array_search("mark $from", $lines, true);
        $end = array_search("mark $to", $lines, true);
        self::assertIsInt($start);
        self::assertIsInt($end);
        return array_values(array_filter(array_slice($lines, $start, $end - $start), fn($l) => str_starts_with($l, 'event ')));
    }

    public function testLoadAndDuration(): void
    {
        self::assertSame('1', $this->value('load'), 'a path with a space and é loads');
        self::assertSame('0', $this->value('load-missing'));
        self::assertEqualsWithDelta(0.5, (float) $this->value('duration'), 0.02);
    }

    public function testPlayPauseSeekStop(): void
    {
        self::assertSame('1', $this->value('playing'));
        self::assertEqualsWithDelta(0.3, (float) $this->value('position'), 0.1);
        self::assertSame('0', $this->value('paused-playing'));
        self::assertLessThan(0.011, (float) $this->value('pause-drift'));
        self::assertEqualsWithDelta(0.1, (float) $this->value('seek-position'), 0.02);
        self::assertSame($this->value('seek-position'), $this->value('seek-nonfinite-position'), 'NaN / infinite seeks are ignored');
        self::assertEqualsWithDelta(0.0, (float) $this->value('stop-position'), 0.001);
        self::assertSame('0', $this->value('stop-playing'));
    }

    public function testProgressAndOneFinished(): void
    {
        $events = $this->eventsBetween('finish-window', 'finish-window-end');
        self::assertNotEmpty(array_filter($events, fn($e) => str_starts_with($e, 'event audio 1 progress ')));
        self::assertSame(['event audio 1 finished'], array_values(array_filter($events, fn($e) => str_contains($e, ' finished'))));
    }

    public function testStaleHandleIsHarmless(): void
    {
        self::assertSame('0.000', $this->value('stale-position'));
        self::assertSame('0', $this->value('stale-playing'));
    }

    public function testHammeringStillFinishesOnce(): void
    {
        $finished = array_filter($this->eventsBetween('hammer', 'hammer-end'), fn($e) => str_contains($e, ' finished'));
        self::assertCount(1, $finished);
    }

    public function testNoEventsAfterUnload(): void
    {
        $lines = $this->lines();
        $at = array_search('unloaded 2', $lines, true);
        self::assertIsInt($at);
        foreach (array_slice($lines, $at) as $line) {
            self::assertStringStartsNotWith('event audio 2 ', $line);
        }
    }

    public function testResetFreesHandlesAndAllowsLoadingAgain(): void
    {
        self::assertSame('0', $this->value('after-reset-playing'));
        self::assertSame('1', $this->value('reload'));
        self::assertSame('1', $this->value('done'));
    }

    private function sh(string $command): void
    {
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));
    }
}
