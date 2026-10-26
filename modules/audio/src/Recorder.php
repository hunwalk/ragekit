<?php

namespace RageKit\Audio;

use RageKit\App;
use RageKit\Events;
use RageKit\Modules;

/**
 * Records from the default microphone as a voice note: 16 kHz, mono, 16-bit WAV, up to 61 seconds.
 * One recording at a time.
 *
 *     $this->recorder = Recorder::start(onLevel: fn(int $level) => $this->level = $level);
 *     …
 *     $note = $this->recorder->stop('voice.wav');   // Recording: path, seconds, waveform (48 bars)
 */
final class Recorder
{
    private bool $active = true;

    private function __construct()
    {
    }

    /**
     * $onLevel(int 0-100) about 10 times a second (a meter); $onFull when 61 seconds are reached
     * (stop() then keeps the first 61). RAGEKIT_AUDIO_NULL=1 records silence from no device.
     *
     * @throws \RuntimeException when no microphone could be opened
     */
    public static function start(?\Closure $onLevel = null, ?\Closure $onFull = null): Recorder
    {
        Modules::require('audio');
        Events::off('audio', 0);
        if (\audio_record_start() === 0) {
            throw new \RuntimeException('recording didn\'t start: ' . \audio_record_error());
        }
        if ($onLevel !== null) {
            Events::on('audio', 0, 'level', function (string $data) use ($onLevel): void {
                $onLevel((int) $data);
            });
        }
        if ($onFull !== null) {
            Events::on('audio', 0, 'full', function (string $data) use ($onFull): void {
                $onFull();
            });
        }
        return new Recorder();
    }

    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Stops and writes the WAV file ($path relative to the app's folder unless absolute).
     *
     * @throws \RuntimeException when it was stopped already or the file couldn't be written
     */
    public function stop(string $path): Recording
    {
        $this->done();
        $file = App::resolve($path);
        $result = \audio_record_stop($file);
        if ($result === '') {
            throw new \RuntimeException('the recording couldn\'t be saved: ' . \audio_record_error());
        }
        [$seconds, $bars] = explode("\n", $result, 2);
        $waveform = [];
        foreach (explode(',', $bars) as $bar) {
            $waveform[] = (int) $bar;
        }
        return new Recording($file, (float) $seconds, $waveform);
    }

    /** Stops without keeping anything. */
    public function cancel(): void
    {
        $this->done();
        \audio_record_cancel();
    }

    private function done(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('this recording has ended');
        }
        $this->active = false;
        Events::off('audio', 0);
    }
}
