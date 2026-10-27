<?php

namespace Fix;

use RageKit\App;
use RageKit\Audio\Sound;
use RageKit\Screen;

/** Loads assets/voice.mp4 (Opus in MP4), prints its duration, plays it, quits when it finishes. */
final class VoiceApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ResultScreen();
    }

    protected function title(): string
    {
        return 'voiceplay';
    }

    protected function start(): void
    {
        try {
            $sound = Sound::load('assets/voice.mp4');
        } catch (\RuntimeException $e) {
            echo 'error ' . $e->getMessage() . "\n";
            $this->quit();
            return;
        }
        echo 'duration ' . number_format($sound->duration(), 1) . "\n";
        $sound->onFinished(function (): void {
            echo "finished\n";
            $this->quit();
        });
        $sound->play();
    }
}
