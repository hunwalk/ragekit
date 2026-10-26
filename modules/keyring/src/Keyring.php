<?php

namespace RageKit\Keyring;

use RageKit\Modules;

/**
 * Secrets in the operating system's credential store, one per service and account: GNOME Keyring /
 * KWallet (the Secret Service) on Linux, Credential Manager on Windows. Secrets are any bytes.
 *
 *     Keyring::set('sh.dev-null', 'device-key', $key->seed());
 *     $seed = Keyring::get('sh.dev-null', 'device-key');   // null when there is none
 *     Keyring::delete('sh.dev-null', 'device-key');
 *
 * Calls wait for the store: a locked keyring asks the user to unlock it first.
 */
final class Keyring
{
    /** Whether the store can be used here (the module's library, and on Linux a Secret Service). */
    public static function available(): bool
    {
        return self::why() === '';
    }

    /** Why the store can't be used ('' when it can). */
    public static function why(): string
    {
        if (!Modules::available('keyring')) {
            return 'the keyring module isn\'t available: ' . \ragekit_module_error('keyring');
        }
        return \keyring_available();
    }

    /** @throws KeyringException */
    public static function set(string $service, string $account, string $secret): void
    {
        self::check($service, $account);
        $error = \keyring_set($service, $account, $secret);
        if ($error !== '') {
            throw new KeyringException($error);
        }
    }

    /** The secret, or null when none is stored. @throws KeyringException */
    public static function get(string $service, string $account): ?string
    {
        self::check($service, $account);
        $answer = \keyring_get($service, $account);
        if ($answer === '-') {
            return null;
        }
        if (str_starts_with($answer, '+')) {
            return (string) hex2bin(substr($answer, 1));
        }
        throw new KeyringException($answer === '' ? 'the keyring failed' : substr($answer, 1));
    }

    /** Removes the secret; nothing happens when there is none. @throws KeyringException */
    public static function delete(string $service, string $account): void
    {
        self::check($service, $account);
        $error = \keyring_delete($service, $account);
        if ($error !== '') {
            throw new KeyringException($error);
        }
    }

    /** Names the stores can't keep apart are refused (invalid UTF-8 comes back as a KeyringException). */
    private static function check(string $service, string $account): void
    {
        if ($service === '' || $account === '') {
            throw new \InvalidArgumentException('a keyring entry needs a service and an account');
        }
        if (str_contains($service . $account, "\0")) {
            throw new \InvalidArgumentException('keyring names can\'t contain NUL bytes');
        }
        if (str_contains($service, ':')) {
            throw new \InvalidArgumentException("a keyring service can't contain ':' (Windows names entries <service>:<account>)");
        }
        Modules::require('keyring');
    }
}
