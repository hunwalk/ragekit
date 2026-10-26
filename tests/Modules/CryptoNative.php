<?php

declare(strict_types=1);

namespace RageKit\Tests\Modules {

    use RageKit\Cli\Modules\Module;
    use RageKit\Cli\Modules\ModuleEmitter;
    use RageKit\Tests\Support\Fetched;

    /**
     * The crypto module's real library, built once per test run and loaded with PHP's FFI. The
     * global \crypto_* functions below (what the generated host gives an app) call into it.
     */
    final class CryptoNative
    {
        public static ?\FFI $ffi = null;
        private static ?string $skip = null;

        /** Why the library can't be used here, or null once it's loaded. */
        public static function load(): ?string
        {
            if (self::$ffi !== null || self::$skip !== null) {
                return self::$skip;
            }
            if (!class_exists(\FFI::class)) {
                return self::$skip = 'needs PHP\'s FFI extension';
            }
            if (trim((string) shell_exec('command -v g++')) === '' || !Fetched::module('crypto')) {
                return self::$skip = 'needs g++ and Monocypher (fetched from the crypto module\'s fetch:)';
            }
            $root = (string) realpath(__DIR__ . '/../..');
            $dir = sys_get_temp_dir() . '/rk-crypto-' . uniqid();
            mkdir($dir);
            $module = Module::load("$root/modules/crypto/module.yml", $root);
            file_put_contents("$dir/rk_module_crypto.h", ModuleEmitter::header($module));
            file_put_contents("$dir/rk_module_crypto_call.cc", ModuleEmitter::callEntry($module));
            $lib = "$dir/librk_module_crypto.so";
            exec("g++ -std=c++17 -O2 -Wall -Wextra -Werror -Wno-unused-function -shared -fPIC -fvisibility=hidden $root/modules/crypto/native/*.cc"
                . " $dir/rk_module_crypto_call.cc -I$dir -I$root/native/include -I$root/modules/crypto/native"
                . " -I$root/modules/crypto/native/third_party/monocypher -o $lib 2>&1", $output, $code);
            if ($code !== 0) {
                throw new \RuntimeException("building the crypto library failed:\n" . implode("\n", $output));
            }
            self::$ffi = \FFI::cdef(<<<'C'
                const char *rkm_crypto_random(int64_t bytes);
                const char *rkm_crypto_sha256(const char *data, size_t data_len);
                const char *rkm_crypto_sha512(const char *data, size_t data_len);
                const char *rkm_crypto_ed25519_public(const char *seed, size_t seed_len);
                const char *rkm_crypto_ed25519_sign(const char *seed, size_t seed_len, const char *message, size_t message_len);
                int rkm_crypto_ed25519_verify(const char *key, size_t key_len, const char *message, size_t message_len, const char *signature, size_t signature_len);
                C, $lib);
            return null;
        }
    }
}

namespace {

    use RageKit\Tests\Modules\CryptoNative;

    function crypto_random(int $bytes): string { return CryptoNative::$ffi->rkm_crypto_random($bytes); }
    function crypto_sha256(string $data): string { return CryptoNative::$ffi->rkm_crypto_sha256($data, strlen($data)); }
    function crypto_sha512(string $data): string { return CryptoNative::$ffi->rkm_crypto_sha512($data, strlen($data)); }
    function crypto_ed25519_public(string $seed): string { return CryptoNative::$ffi->rkm_crypto_ed25519_public($seed, strlen($seed)); }
    function crypto_ed25519_sign(string $seed, string $message): string
    {
        return CryptoNative::$ffi->rkm_crypto_ed25519_sign($seed, strlen($seed), $message, strlen($message));
    }
    function crypto_ed25519_verify(string $key, string $message, string $signature): bool
    {
        return CryptoNative::$ffi->rkm_crypto_ed25519_verify($key, strlen($key), $message, strlen($message), $signature, strlen($signature)) === 1;
    }
}
