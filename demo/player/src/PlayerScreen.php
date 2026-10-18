<?php

namespace Demo;

use RageKit\Audio\Sound;
use RageKit\Modules;
use RageKit\Screen;

/** Plays assets/tone.wav with the audio module. */
final class PlayerScreen extends Screen
{
    public string $status = 'Stopped';
    public float $volume = 0.8;
    private ?Sound $sound = null;

    public static function template(): string
    {
        return 'templates/Player.rk';
    }

    public function available(): bool
    {
        return Modules::available('audio');
    }

    public function play(): void
    {
        $sound = $this->sound();
        if ($sound !== null) {
            $sound->play();
            $this->status = 'Playing';
        }
    }

    public function pause(): void
    {
        if ($this->sound !== null) {
            $this->sound->pause();
            $this->status = 'Paused';
        }
    }

    public function stop(): void
    {
        if ($this->sound !== null) {
            $this->sound->stop();
            $this->status = 'Stopped';
        }
    }

    public function progress(): float
    {
        if ($this->sound === null) {
            return 0.0;
        }
        $duration = $this->sound->duration();
        return $duration > 0 ? $this->sound->position() / $duration : 0.0;
    }

    public function timeText(): string
    {
        if ($this->sound === null) {
            return '0.0 s / 0.0 s';
        }
        return sprintf('%.1f s / %.1f s', $this->sound->position(), $this->sound->duration());
    }

    /** A two-way slider binding has no change handler, so the volume is applied when it's shown. (demo only) */
    public function volumeText(): string
    {
        if ($this->sound !== null) {
            $this->sound->setVolume($this->volume);
        }
        return 'Volume ' . (int) round($this->volume * 100) . '%';
    }

    private function sound(): ?Sound
    {
        if ($this->sound === null && Modules::available('audio')) {
            $sound = Sound::load('assets/tone.wav');
            $sound->onFinished(function (): void {
                $this->status = 'Finished';
            });
            $sound->setVolume($this->volume);
            $this->sound = $sound;
        }
        return $this->sound;
    }
}
