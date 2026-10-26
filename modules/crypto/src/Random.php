<?php

namespace RageKit\Crypto;

use RageKit\Modules;

/** Random bytes from the operating system (getrandom on Linux, BCryptGenRandom on Windows). */
final class Random
{
    /** $count raw bytes, 1 to 65536. */
    public static function bytes(int $count): string
    {
        if ($count < 1 || $count > 65536) {
            throw new \InvalidArgumentException('Random::bytes() takes 1 to 65536 bytes');
        }
        Modules::require('crypto');
        $hex = \crypto_random($count);
        if ($hex === '') {
            throw new \RuntimeException('the operating system gave no random bytes');
        }
        return (string) hex2bin($hex);
    }
}
