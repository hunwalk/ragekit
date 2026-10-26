<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Audio\Recorder;
use RageKit\Cli\Modules\Module;
use RageKit\Cli\Modules\ModuleEmitter;
use RageKit\Events;
use RageKit\Tests\Slint\Runtime\FakeHost;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';
require_once __DIR__ . '/../../modules/audio/src/Recorder.php';
require_once __DIR__ . '/../../modules/audio/src/Recording.php';

/**
 * Recording (audio module): the PHP API over fake \audio_record_* functions, and the library through
 * tests/fixtures/modules/probe_record.cc on miniaudio's null device (silence; never a real microphone).
 */
final class AudioRecordTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        FakeHost::reset();
        FakeHost::$modules['audio'] = '';
        FakeHost::$recording = '';
    }

    public function testStartLevelsAndStop(): void
    {
        $levels = [];
        $recorder = Recorder::start(function (int $level) use (&$levels): void { $levels[] = $level; });
        Events::deliver('audio', 0, 'level', '42');
        FakeHost::$recording = "1.500\n" . implode(',', range(0, 47));
        $note = $recorder->stop('/tmp/note.wav');
        self::assertSame([42], $levels);
        self::assertSame(['/tmp/note.wav', 1.5, range(0, 47)], [$note->path, $note->seconds, $note->waveform]);
        Events::deliver('audio', 0, 'level', '7');
        self::assertSame([42], $levels, 'no levels after stop()');
        $this->expectException(\RuntimeException::class);
        $recorder->stop('/tmp/again.wav');
    }

    public function testAFailedSaveSaysWhy(): void
    {
        $recorder = Recorder::start();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the recording couldn\'t be saved: couldn\'t write /nope/x.wav');
        $recorder->stop('/nope/x.wav');
    }

    public function testLibraryRecordsAVoiceNoteWav(): void
    {
        if (trim((string) shell_exec('command -v g++')) === '' || !\RageKit\Tests\Support\Fetched::module('audio')) {
            self::markTestSkipped('needs g++ and miniaudio (fetched from the audio module\'s fetch:)');
        }
        $dir = sys_get_temp_dir() . '/rk-record-' . uniqid();
        mkdir($dir);
        $root = (string) realpath(self::ROOT);
        $module = Module::load("$root/modules/audio/module.yml", $root);
        file_put_contents("$dir/rk_module_audio.h", ModuleEmitter::header($module));
        file_put_contents("$dir/rk_module_audio_call.cc", ModuleEmitter::callEntry($module));
        exec("g++ -std=c++17 -O1 -shared -fPIC -fvisibility=hidden $root/modules/audio/native/audio.cc $dir/rk_module_audio_call.cc -I$dir -I$root/native/include"
            . " -I$root/modules/audio/native -I$root/modules/audio/native/third_party/miniaudio -ldl -lpthread -lm -o $dir/librk_module_audio.so 2>&1"
            . " && g++ -std=c++17 $root/tests/fixtures/modules/probe_record.cc -ldl -lpthread -o $dir/probe 2>&1", $build, $code);
        self::assertSame(0, $code, implode("\n", $build));
        exec('RAGEKIT_AUDIO_NULL=1 ' . escapeshellarg("$dir/probe") . ' ' . escapeshellarg("$dir/librk_module_audio.so") . ' ' . escapeshellarg("$dir/note.wav") . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        $out = implode("\n", $lines);
        self::assertMatchesRegularExpression('/^result 1\.[45]\d\d$/m', $out, 'about 1.5 s');
        self::assertStringContainsString(implode(',', array_fill(0, 48, '0')), $out, '48 bars, silent');
        self::assertMatchesRegularExpression('/^levels (1[0-9]|2[0-9])$/m', $out, 'about 10 a second');
        $wav = (string) file_get_contents("$dir/note.wav");
        // As the dev-null server checks it: RIFF/WAVE, PCM, mono, 16 kHz, 32000 bytes/s, 16-bit.
        self::assertSame('RIFF', substr($wav, 0, 4));
        self::assertSame('WAVE', substr($wav, 8, 4));
        self::assertSame(pack('vvVVvv', 1, 1, 16000, 32000, 2, 16), substr($wav, 20, 16));
        self::assertSame(strlen($wav) - 44, unpack('V', substr($wav, 40, 4))[1]);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
