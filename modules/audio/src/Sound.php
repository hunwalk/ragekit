<?php

namespace RageKit\Audio;

use RageKit\App;
use RageKit\Events;
use RageKit\Modules;

/**
 * A loaded sound (audio module). Paths are relative to the app's folder — list the files under
 * `ragekit: assets:` so they are copied next to the executable. Formats: WAV, FLAC, MP3.
 *
 *     $song = Sound::load('assets/song.mp3');
 *     $song->onFinished(function (): void { $this->status = 'Done'; });
 *     $song->play();
 */
final class Sound
{
    private function __construct(private int $id)
    {
    }

    /**
     * @throws \RageKit\ModuleUnavailable when the audio module isn't next to the app
     * @throws \RuntimeException when the file can't be loaded
     */
    public static function load(string $path): Sound
    {
        Modules::require('audio');
        $id = \audio_load(App::resolve($path));
        if ($id === 0) {
            throw new \RuntimeException("can't load sound $path");
        }
        return new Sound($id);
    }

    public function play(): void
    {
        \audio_play($this->id);
    }

    public function pause(): void
    {
        \audio_pause($this->id);
    }

    /** Stops and rewinds to the start. */
    public function stop(): void
    {
        \audio_stop($this->id);
    }

    public function seek(float $seconds): void
    {
        \audio_seek($this->id, $seconds);
    }

    /** 0 = silent, 1 = as recorded. */
    public function setVolume(float $volume): void
    {
        \audio_volume($this->id, $volume);
    }

    /** Seconds from the start. */
    public function position(): float
    {
        return \audio_position($this->id);
    }

    /** Length in seconds. */
    public function duration(): float
    {
        return \audio_duration($this->id);
    }

    public function isPlaying(): bool
    {
        return \audio_playing($this->id);
    }

    /** Runs when the sound plays to its end (on the UI thread; windows refresh afterwards). */
    public function onFinished(\Closure $fn): void
    {
        Events::on('audio', $this->id, 'finished', function (string $data) use ($fn): void {
            $fn();
        });
    }

    /** Runs about 4 times a second while playing, with the position in seconds. */
    public function onProgress(\Closure $fn): void
    {
        Events::on('audio', $this->id, 'progress', function (string $data) use ($fn): void {
            $fn((float) $data);
        });
    }

    /** Frees the sound; this object can't be used afterwards. */
    public function unload(): void
    {
        \audio_unload($this->id);
        Events::off('audio', $this->id);
    }
}
