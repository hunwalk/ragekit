// The crypto module's library: Ed25519 and SHA-512 from Monocypher, SHA-256 (sha256.cc), and the
// OS's randomness. No threads, no events. Results are lowercase hex in a thread_local buffer (a
// module result is a NUL-terminated string valid until the next call); '' means bad input.
#include "rk_module_crypto.h"
#include "sha256.h"

#include "monocypher-ed25519.h"
#include "monocypher.h"

#include <cstdint>
#include <string>

#ifdef _WIN32
#include <windows.h>
#include <bcrypt.h>
#else
#include <cerrno>
#include <sys/random.h>
#endif

namespace {

thread_local std::string result;

const char *hex(const uint8_t *bytes, size_t len)
{
    static const char digits[] = "0123456789abcdef";
    crypto_wipe(result.data(), result.size()); // the previous result may have been random key material
    result.assign(len * 2, '0');
    for (size_t i = 0; i < len; i++) {
        result[i * 2] = digits[bytes[i] >> 4];
        result[i * 2 + 1] = digits[bytes[i] & 15];
    }
    return result.c_str();
}

const char *none()
{
    crypto_wipe(result.data(), result.size());
    result.clear();
    return result.c_str();
}

const uint8_t *bytes(const char *data)
{
    return reinterpret_cast<const uint8_t *>(data);
}

bool fill(uint8_t *out, size_t len)
{
#ifdef _WIN32 // Windows: untested
    return BCryptGenRandom(nullptr, out, static_cast<ULONG>(len), BCRYPT_USE_SYSTEM_PREFERRED_RNG) == 0;
#else
    size_t done = 0;
    while (done < len) {
        ssize_t got = getrandom(out + done, len - done, 0);
        if (got < 0) {
            if (errno == EINTR) {
                continue;
            }
            return false;
        }
        done += static_cast<size_t>(got);
    }
    return true;
#endif
}

/** Monocypher's 64-byte Ed25519 secret key (seed + public key) from a 32-byte seed; wiped by the caller. */
void keyPair(uint8_t secret[64], uint8_t publicKey[32], const char *seed)
{
    uint8_t copy[32];
    for (int i = 0; i < 32; i++) {
        copy[i] = bytes(seed)[i];
    }
    crypto_ed25519_key_pair(secret, publicKey, copy); // wipes copy
}

} // namespace

extern "C" {

RKM_API void rkm_crypto_init(rk_post_fn)
{
}

RKM_API void rkm_crypto_reset(void)
{
}

RKM_API const char *rkm_crypto_random(int64_t count)
{
    if (count < 1 || count > 65536) {
        return none();
    }
    std::string buffer(static_cast<size_t>(count), '\0');
    uint8_t *out = reinterpret_cast<uint8_t *>(buffer.data());
    if (!fill(out, buffer.size())) {
        return none();
    }
    const char *text = hex(out, buffer.size());
    crypto_wipe(out, buffer.size());
    return text;
}

RKM_API const char *rkm_crypto_sha256(const char *data, size_t data_len)
{
    auto digest = rk::crypto::sha256(bytes(data), data_len);
    return hex(digest.data(), digest.size());
}

RKM_API const char *rkm_crypto_sha512(const char *data, size_t data_len)
{
    uint8_t digest[64];
    crypto_sha512(digest, bytes(data), data_len);
    return hex(digest, sizeof digest);
}

RKM_API const char *rkm_crypto_ed25519_public(const char *seed, size_t seed_len)
{
    if (seed_len != 32) {
        return none();
    }
    uint8_t secret[64];
    uint8_t publicKey[32];
    keyPair(secret, publicKey, seed);
    crypto_wipe(secret, sizeof secret);
    return hex(publicKey, sizeof publicKey);
}

RKM_API const char *rkm_crypto_ed25519_sign(const char *seed, size_t seed_len, const char *message, size_t message_len)
{
    if (seed_len != 32) {
        return none();
    }
    uint8_t secret[64];
    uint8_t publicKey[32];
    uint8_t signature[64];
    keyPair(secret, publicKey, seed);
    crypto_ed25519_sign(signature, secret, bytes(message), message_len);
    crypto_wipe(secret, sizeof secret);
    return hex(signature, sizeof signature);
}

RKM_API int rkm_crypto_ed25519_verify(const char *key, size_t key_len, const char *message, size_t message_len,
                                      const char *signature, size_t signature_len)
{
    if (key_len != 32 || signature_len != 64) {
        return 0;
    }
    return crypto_ed25519_check(bytes(signature), bytes(key), bytes(message), message_len) == 0 ? 1 : 0;
}

} // extern "C"
