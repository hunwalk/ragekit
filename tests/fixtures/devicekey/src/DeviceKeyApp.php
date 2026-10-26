<?php

namespace Fix;

use RageKit\App;
use RageKit\Crypto\Ed25519Key;
use RageKit\Crypto\Ssh;
use RageKit\Keyring\Keyring;
use RageKit\Keyring\KeyringException;
use RageKit\Screen;
use RageKit\Timer;

/**
 * Loads its device key from the keyring, or makes one and stores it, prints its fingerprint and a
 * signature check, then quits.
 */
final class DeviceKeyApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ResultScreen();
    }

    protected function title(): string
    {
        return 'devicekey';
    }

    protected function start(): void
    {
        echo 'keyring ' . (Keyring::available() ? 'available' : 'unavailable: ' . Keyring::why()) . "\n";
        try {
            $seed = Keyring::get('org.ragekit.devicekey', 'device-key');
            if ($seed === null) {
                $key = Ed25519Key::generate();
                Keyring::set('org.ragekit.devicekey', 'device-key', $key->seed());
                echo "created\n";
            } else {
                $key = Ed25519Key::fromSeed($seed);
                echo "loaded\n";
            }
            echo 'fingerprint ' . Ssh::fingerprint($key) . "\n";
            echo 'signs ' . (Ed25519Key::verify($key->publicKey(), 'x', $key->sign('x')) ? 'yes' : 'no') . "\n";
        } catch (KeyringException $e) {
            echo 'error ' . $e->getMessage() . "\n";
        }
        Timer::after(0, function (): void {
            $this->quit();
        });
    }
}
