<?php
// Writes assets/tone.wav: 3 s of a 440 Hz tone (22050 Hz, mono, 16-bit) with 50 ms fades.
// Run with plain PHP: php make-tone.php

$rate = 22050;
$frames = 3 * $rate;
$fade = (int) (0.05 * $rate);
$data = '';
for ($i = 0; $i < $frames; $i++) {
    $gain = min(1.0, $i / $fade, ($frames - 1 - $i) / $fade);
    $data .= pack('v', (int) round(sin(2 * M_PI * 440 * $i / $rate) * 12000 * $gain) & 0xFFFF);
}
file_put_contents(__DIR__ . '/assets/tone.wav', 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt '
    . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data);
