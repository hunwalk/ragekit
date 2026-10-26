<?php

namespace RageKit\Crypto;

use RageKit\Modules;

/** SHA-256 and SHA-512, hex by default like PHP's hash() (which Nano builds don't all have). */
final class Hash
{
    public static function sha256(string $data, bool $binary = false): string
    {
        Modules::require('crypto');
        $hex = \crypto_sha256($data);
        return $binary ? (string) hex2bin($hex) : $hex;
    }

    public static function sha512(string $data, bool $binary = false): string
    {
        Modules::require('crypto');
        $hex = \crypto_sha512($data);
        return $binary ? (string) hex2bin($hex) : $hex;
    }
}
