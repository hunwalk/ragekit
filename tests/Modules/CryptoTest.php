<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules;

use PHPUnit\Framework\TestCase;
use RageKit\Crypto\Ed25519Key;
use RageKit\Crypto\Hash;
use RageKit\Crypto\Random;
use RageKit\Crypto\Ssh;
use RageKit\ModuleUnavailable;
use RageKit\Tests\Slint\Runtime\FakeHost;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';
require_once __DIR__ . '/../../modules/crypto/src/Random.php';
require_once __DIR__ . '/../../modules/crypto/src/Hash.php';
require_once __DIR__ . '/../../modules/crypto/src/Ed25519Key.php';
require_once __DIR__ . '/../../modules/crypto/src/Ssh.php';

/** The crypto module: its PHP API on the real library (CryptoNative, through FFI). */
final class CryptoTest extends TestCase
{
    /** RFC 8032 section 7.1, tests 1-3: seed, public key, message, signature (hex). */
    private const RFC8032 = [
        ['9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60', 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a', '',
            'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b'],
        ['4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb', '3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c', '72',
            '92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00'],
        ['c5aa8df43f9f837bedb7442f31dcb7b166d38535076f094b85ce3a2e0b4458f7', 'fc51cd8e6218a1a38da47ed00230f0580816ed13ba3303ac5deb911548908025', 'af82',
            '6291d657deec24024827e69c3abe01a30ce548a284743a445e3680d7db5ac3ac18ff9b538d16f290ae67f760984dc6594a7c15e9716ed28dc027beceea1ec40a'],
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        $skip = CryptoNative::load();
        if ($skip !== null) {
            self::markTestSkipped($skip);
        }
        FakeHost::reset();
        FakeHost::$modules['crypto'] = '';
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testRfc8032Vectors(): void
    {
        foreach (self::RFC8032 as [$seed, $public, $message, $signature]) {
            $key = Ed25519Key::fromSeed((string) hex2bin($seed));
            self::assertSame($public, bin2hex($key->publicKey()));
            self::assertSame($signature, bin2hex($key->sign((string) hex2bin($message))));
            self::assertTrue(Ed25519Key::verify($key->publicKey(), (string) hex2bin($message), (string) hex2bin($signature)));
        }
    }

    public function testMatchesSodiumOnRandomKeysAndMessages(): void
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            self::markTestSkipped('needs PHP\'s sodium extension');
        }
        for ($i = 0; $i < 50; $i++) {
            $seed = random_bytes(32);
            $message = $i === 0 ? '' : random_bytes($i * 37);
            $pair = sodium_crypto_sign_seed_keypair($seed);
            $key = Ed25519Key::fromSeed($seed);
            self::assertSame(sodium_crypto_sign_publickey($pair), $key->publicKey());
            self::assertSame(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair)), $key->sign($message));
        }
    }

    public function testVerifyRejectsChangesAndBadSizes(): void
    {
        $key = Ed25519Key::generate();
        $signature = $key->sign("a\0b");
        self::assertTrue(Ed25519Key::verify($key->publicKey(), "a\0b", $signature), 'messages are binary-safe');
        self::assertFalse(Ed25519Key::verify($key->publicKey(), "a\0c", $signature));
        self::assertFalse(Ed25519Key::verify($key->publicKey(), "a\0b", $signature ^ str_repeat("\0", 63) . "\1"));
        self::assertFalse(Ed25519Key::verify(Ed25519Key::generate()->publicKey(), "a\0b", $signature));
        self::assertFalse(Ed25519Key::verify($key->publicKey(), "a\0b", substr($signature, 1)));
        self::assertFalse(Ed25519Key::verify(substr($key->publicKey(), 1), "a\0b", $signature));
    }

    public function testSeedRoundTripsAndMustBe32Bytes(): void
    {
        $key = Ed25519Key::generate();
        self::assertSame(32, strlen($key->seed()));
        self::assertSame($key->publicKey(), Ed25519Key::fromSeed($key->seed())->publicKey());
        $this->expectException(\InvalidArgumentException::class);
        Ed25519Key::fromSeed(str_repeat('x', 31));
    }

    public function testHashesMatchPhp(): void
    {
        foreach ([0, 1, 55, 56, 63, 64, 65, 119, 120, 128, 1000, 1 << 20] as $length) {
            $data = $length === 0 ? '' : random_bytes($length);
            self::assertSame(hash('sha256', $data), Hash::sha256($data), "sha256 of $length bytes");
            self::assertSame(hash('sha512', $data), Hash::sha512($data), "sha512 of $length bytes");
            self::assertSame(hash('sha256', $data, true), Hash::sha256($data, true));
        }
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', Hash::sha256('abc'), 'FIPS 180-4 example');
    }

    public function testRandomBytes(): void
    {
        self::assertSame(32, strlen(Random::bytes(32)));
        self::assertSame(65536, strlen(Random::bytes(65536)));
        self::assertNotSame(Random::bytes(16), Random::bytes(16));
        $this->expectException(\InvalidArgumentException::class);
        Random::bytes(0);
    }

    public function testSshSignatureVerifiesWithSshKeygen(): void
    {
        $this->needSshKeygen();
        $key = Ed25519Key::generate();
        $message = "1761470000\nabc123\nPOST\n/api/v1/remotes?x=1\n{\"text\":\"hi \u{1F44B}\"}";
        $this->write('allowed', 'device@test ' . Ssh::publicKey($key) . "\n");
        $this->write('message', $message);
        $this->write('message.sig', "-----BEGIN SSH SIGNATURE-----\n" . chunk_split(Ssh::sign($key, $message, 'devnull-api'), 70, "\n") . "-----END SSH SIGNATURE-----\n");

        self::assertSame(0, $this->verify('devnull-api'), 'ssh-keygen -Y verify accepts it');
        self::assertNotSame(0, $this->verify('devnull-api-images'), 'a signature names its namespace');
        $this->write('message', $message . ' ');
        self::assertNotSame(0, $this->verify('devnull-api'), 'and covers every byte');
    }

    public function testPublicKeyLineAndFingerprintMatchSshKeygen(): void
    {
        $this->needSshKeygen();
        $key = Ed25519Key::fromSeed((string) hex2bin(self::RFC8032[0][0]));
        $this->write('key.pub', Ssh::publicKey($key, 'me@host') . "\n");
        exec('ssh-keygen -l -E sha256 -f ' . escapeshellarg("$this->dir/key.pub") . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertSame('256 ' . Ssh::fingerprint($key) . ' me@host (ED25519)', $output[0]);
        self::assertStringStartsWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI', Ssh::publicKey($key));
    }

    public function testSignaturesMatchSshKeygenByteForByte(): void
    {
        // Ed25519 is deterministic, so ssh-keygen signing with the same key gives the same blob.
        $this->needSshKeygen();
        $key = Ed25519Key::fromSeed((string) hex2bin(self::RFC8032[1][0]));
        $this->write('id', $this->openSshPrivateKey($key));
        chmod("$this->dir/id", 0600);
        $this->write('data', "hello\nworld");
        exec('ssh-keygen -q -Y sign -f ' . escapeshellarg("$this->dir/id") . ' -n file ' . escapeshellarg("$this->dir/data") . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        $armored = (string) file_get_contents("$this->dir/data.sig");
        $body = preg_replace(['/-----(BEGIN|END) SSH SIGNATURE-----/', '/\s+/'], '', $armored);
        self::assertSame($body, Ssh::sign($key, "hello\nworld", 'file'));
    }

    public function testMissingLibraryThrows(): void
    {
        unset(FakeHost::$modules['crypto']);
        $this->expectException(ModuleUnavailable::class);
        Hash::sha256('x');
    }

    private function needSshKeygen(): void
    {
        if (trim((string) shell_exec('command -v ssh-keygen')) === '') {
            self::markTestSkipped('needs ssh-keygen');
        }
        if ($this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/rk-ssh-' . uniqid();
            mkdir($this->dir);
        }
    }

    private function write(string $name, string $content): void
    {
        file_put_contents("$this->dir/$name", $content);
    }

    private function verify(string $namespace): int
    {
        exec('ssh-keygen -Y verify -f ' . escapeshellarg("$this->dir/allowed") . ' -I device@test -n ' . escapeshellarg($namespace)
            . ' -s ' . escapeshellarg("$this->dir/message.sig") . ' < ' . escapeshellarg("$this->dir/message") . ' 2>&1', $output, $code);
        return $code;
    }

    /** The key as an unencrypted "openssh-key-v1" file, so ssh-keygen can sign with it. */
    private function openSshPrivateKey(Ed25519Key $key): string
    {
        $string = fn(string $s): string => pack('N', strlen($s)) . $s;
        $check = random_bytes(4);
        $private = $check . $check . $string('ssh-ed25519') . $string($key->publicKey())
            . $string($key->seed() . $key->publicKey()) . $string('test');
        for ($i = 1; strlen($private) % 8 !== 0; $i++) {
            $private .= chr($i);
        }
        $blob = "openssh-key-v1\0" . $string('none') . $string('none') . $string('') . pack('N', 1)
            . $string(Ssh::publicKeyBlob($key)) . $string($private);
        return "-----BEGIN OPENSSH PRIVATE KEY-----\n" . chunk_split(base64_encode($blob), 70, "\n") . "-----END OPENSSH PRIVATE KEY-----\n";
    }
}
