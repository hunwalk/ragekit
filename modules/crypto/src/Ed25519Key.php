<?php

namespace RageKit\Crypto;

use RageKit\Modules;

/**
 * An Ed25519 private key (RFC 8032), held as its 32-byte seed. Keep the seed somewhere safe, e.g.
 * RageKit\Keyring\Keyring::set(), and load it back with fromSeed().
 *
 *     $key = Ed25519Key::generate();
 *     $signature = $key->sign('hello');                 // 64 raw bytes
 *     Ed25519Key::verify($key->publicKey(), 'hello', $signature);
 */
final class Ed25519Key
{
    private function __construct(private string $seed, private string $public)
    {
    }

    public static function generate(): Ed25519Key
    {
        return self::fromSeed(Random::bytes(32));
    }

    /** @param string $seed the 32-byte private key, as seed() returned it */
    public static function fromSeed(string $seed): Ed25519Key
    {
        if (strlen($seed) !== 32) {
            throw new \InvalidArgumentException('an Ed25519 seed is 32 bytes, got ' . strlen($seed));
        }
        Modules::require('crypto');
        return new Ed25519Key($seed, (string) hex2bin(\crypto_ed25519_public($seed)));
    }

    /** The 32-byte private key. */
    public function seed(): string
    {
        return $this->seed;
    }

    /** The 32-byte public key. */
    public function publicKey(): string
    {
        return $this->public;
    }

    /** The 64-byte signature of $message. */
    public function sign(string $message): string
    {
        Modules::require('crypto');
        return (string) hex2bin(\crypto_ed25519_sign($this->seed, $message));
    }

    /** Whether $signature (64 bytes) is $publicKey's (32 bytes) signature of $message. */
    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        Modules::require('crypto');
        return \crypto_ed25519_verify($publicKey, $message, $signature);
    }
}
