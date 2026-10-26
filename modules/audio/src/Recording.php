<?php

namespace RageKit\Audio;

/** A finished recording: the WAV file, how long it is, and its loudness in 48 bars (0-100). */
final class Recording
{
    /** @param list<int> $waveform */
    public function __construct(public readonly string $path, public readonly float $seconds, public readonly array $waveform)
    {
    }
}
