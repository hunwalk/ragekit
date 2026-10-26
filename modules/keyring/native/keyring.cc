// The keyring module's library: one secret per (service, account) in the OS's credential store.
// Calls are synchronous: quick normally; a locked store shows the OS's unlock prompt (another
// process) and the call waits for it. Results live in a thread_local buffer until the next call.
#include "rk_module_keyring.h"
#include "store.h"

#include <string>

namespace {

thread_local std::string result;

/** Overwrites a string that held secret material (the compiler may not drop a volatile write). */
void wipe(std::string &text)
{
    volatile char *p = text.data();
    for (size_t i = 0; i < text.size(); i++) {
        p[i] = 0;
    }
}

const char *answer(std::string text)
{
    wipe(result); // the previous answer may have been a secret
    result = std::move(text);
    return result.c_str();
}

/** Strict UTF-8, as GLib (and D-Bus) want it: no overlong forms, surrogates or values past U+10FFFF. */
bool utf8(const std::string &text)
{
    for (size_t i = 0; i < text.size();) {
        unsigned char c = static_cast<unsigned char>(text[i]);
        size_t extra = c < 0x80 ? 0 : (c >= 0xC2 && c <= 0xDF) ? 1 : (c >= 0xE0 && c <= 0xEF) ? 2 : (c >= 0xF0 && c <= 0xF4) ? 3 : 4;
        if (extra == 4 || extra > text.size() - i - 1) {
            return false;
        }
        for (size_t k = 1; k <= extra; k++) {
            if ((static_cast<unsigned char>(text[i + k]) & 0xC0) != 0x80) {
                return false;
            }
        }
        unsigned char next = extra > 0 ? static_cast<unsigned char>(text[i + 1]) : 0;
        if ((c == 0xE0 && next < 0xA0) || (c == 0xED && next >= 0xA0) || (c == 0xF0 && next < 0x90) || (c == 0xF4 && next >= 0x90)) {
            return false;
        }
        i += extra + 1;
    }
    return true;
}

/** Why a service/account pair can't name an entry ('' when it can): stores cut names at NUL, and
 *  Windows joins them as "<service>:<account>", so a ':' in the service would be ambiguous. */
std::string invalid(const std::string &service, const std::string &account)
{
    if (service.empty() || account.empty()) {
        return "a keyring entry needs a service and an account";
    }
    if (service.find('\0') != std::string::npos || account.find('\0') != std::string::npos) {
        return "keyring names can't contain NUL bytes";
    }
    if (!utf8(service) || !utf8(account)) {
        return "keyring names must be UTF-8";
    }
    if (service.find(':') != std::string::npos) {
        return "a keyring service can't contain ':'";
    }
    return "";
}

std::string text(const char *data, size_t len)
{
    return std::string(data, len);
}

std::string hex(const std::string &bytes)
{
    static const char digits[] = "0123456789abcdef";
    std::string out(bytes.size() * 2, '0');
    for (size_t i = 0; i < bytes.size(); i++) {
        unsigned char c = static_cast<unsigned char>(bytes[i]);
        out[i * 2] = digits[c >> 4];
        out[i * 2 + 1] = digits[c & 15];
    }
    return out;
}

} // namespace

extern "C" {

RKM_API void rkm_keyring_init(rk_post_fn)
{
}

RKM_API void rkm_keyring_reset(void)
{
}

RKM_API const char *rkm_keyring_available(void)
{
    return answer(rk::keyring::available());
}

RKM_API const char *rkm_keyring_set(const char *service, size_t service_len, const char *account, size_t account_len,
                                    const char *secret, size_t secret_len)
{
    std::string name = text(service, service_len);
    std::string user = text(account, account_len);
    std::string bad = invalid(name, user);
    if (!bad.empty()) {
        return answer(bad);
    }
    std::string value = text(secret, secret_len);
    std::string error = rk::keyring::set(name, user, value);
    wipe(value);
    return answer(error);
}

RKM_API const char *rkm_keyring_get(const char *service, size_t service_len, const char *account, size_t account_len)
{
    std::string name = text(service, service_len);
    std::string user = text(account, account_len);
    std::string bad = invalid(name, user);
    if (!bad.empty()) {
        return answer("!" + bad);
    }
    rk::keyring::Found found = rk::keyring::get(name, user);
    if (!found.error.empty()) {
        return answer("!" + found.error);
    }
    if (!found.found) {
        return answer("-");
    }
    std::string out = "+" + hex(found.secret);
    wipe(found.secret);
    return answer(std::move(out));
}

RKM_API const char *rkm_keyring_delete(const char *service, size_t service_len, const char *account, size_t account_len)
{
    std::string name = text(service, service_len);
    std::string user = text(account, account_len);
    std::string bad = invalid(name, user);
    return answer(bad.empty() ? rk::keyring::remove(name, user) : bad);
}

} // extern "C"
