<?php

namespace RageKit\Crypto;

/**
 * OpenSSH formats for an Ed25519Key: the public key line, its fingerprint, and signatures in the
 * SSHSIG format (https://github.com/openssh/openssh-portable/blob/master/PROTOCOL.sshsig), as
 * `ssh-keygen -Y sign -n <namespace>` makes them (with SHA-512).
 */
final class Ssh
{
    /** `ssh-ed25519 AAAA… comment`, as in authorized_keys or allowed_signers. */
    public static function publicKey(Ed25519Key $key, string $comment = ''): string
    {
        $line = 'ssh-ed25519 ' . base64_encode(self::publicKeyBlob($key));
        return $comment === '' ? $line : $line . ' ' . $comment;
    }

    /** `SHA256:…` (base64 without padding), as `ssh-keygen -l` prints it. */
    public static function fingerprint(Ed25519Key $key): string
    {
        return 'SHA256:' . rtrim(base64_encode(Hash::sha256(self::publicKeyBlob($key), true)), '=');
    }

    /** The signature's base64 on one line: the body of an armored `-----BEGIN SSH SIGNATURE-----` block. */
    public static function sign(Ed25519Key $key, string $message, string $namespace): string
    {
        if ($namespace === '') {
            throw new \InvalidArgumentException('an SSH signature needs a namespace');
        }
        $signed = 'SSHSIG' . self::string($namespace) . self::string('') . self::string('sha512')
            . self::string(Hash::sha512($message, true));
        $signature = self::string('ssh-ed25519') . self::string($key->sign($signed));
        $blob = 'SSHSIG' . self::uint32(1) . self::string(self::publicKeyBlob($key)) . self::string($namespace)
            . self::string('') . self::string('sha512') . self::string($signature);
        return base64_encode($blob);
    }

    /** The public key in SSH's wire format: string "ssh-ed25519", string key. */
    public static function publicKeyBlob(Ed25519Key $key): string
    {
        return self::string('ssh-ed25519') . self::string($key->publicKey());
    }

    private static function string(string $value): string
    {
        return self::uint32(strlen($value)) . $value;
    }

    /** Big-endian, as SSH writes lengths (Nano builds have no pack()). */
    private static function uint32(int $value): string
    {
        return chr(($value >> 24) & 255) . chr(($value >> 16) & 255) . chr(($value >> 8) & 255) . chr($value & 255);
    }
}
