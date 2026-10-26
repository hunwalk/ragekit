<?php

namespace Fix;

use RageKit\App;
use RageKit\Crypto\Ed25519Key;
use RageKit\Crypto\Hash;
use RageKit\Crypto\Random;
use RageKit\Crypto\Ssh;
use RageKit\Modules;
use RageKit\Screen;
use RageKit\Timer;

/** Prints what the crypto module computes for fixed inputs (RFC 8032 test 2's key), then quits. */
final class CryptoApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ResultScreen();
    }

    protected function title(): string
    {
        return 'cryptoapp';
    }

    protected function start(): void
    {
        echo 'available ' . (Modules::available('crypto') ? 'yes' : 'no') . "\n";
        $key = Ed25519Key::fromSeed((string) hex2bin('4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb'));
        echo 'public ' . bin2hex($key->publicKey()) . "\n";
        $signature = $key->sign((string) hex2bin('72'));
        echo 'signature ' . bin2hex($signature) . "\n";
        echo 'verify ' . (Ed25519Key::verify($key->publicKey(), (string) hex2bin('72'), $signature) ? 'yes' : 'no') . "\n";
        echo 'sha256 ' . Hash::sha256('abc') . "\n";
        echo 'sha512 ' . Hash::sha512('abc') . "\n";
        echo 'random ' . strlen(Random::bytes(32)) . "\n";
        echo 'ssh ' . Ssh::publicKey($key, 'test') . "\n";
        echo 'fingerprint ' . Ssh::fingerprint($key) . "\n";
        echo 'sshsig ' . Ssh::sign($key, "hello\nworld", 'file') . "\n";
        // Bytes the dev protocol escapes (and NUL): arguments must arrive unchanged.
        $awkward = Ed25519Key::fromSeed(str_repeat("\0\t\n\r\\", 6) . "\xff\x80");
        echo 'awkward ' . bin2hex($awkward->publicKey()) . ' ' . bin2hex($awkward->sign("\0\n\\t")) . "\n";
        // Not crypto: RageKit\LocalTime, checked here because this app only prints (crypto.sh sets TZ).
        echo 'localtime ' . \RageKit\LocalTime::format('Y-m-d H:i', 0) . "\n";
        Timer::after(0, function (): void {
            $this->quit();
        });
    }
}
