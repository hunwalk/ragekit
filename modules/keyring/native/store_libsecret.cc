// Linux (and other non-Windows systems): the Secret Service over D-Bus (GNOME Keyring, KWallet,
// KeePassXC…) through libsecret's simple password API. libsecret is opened with dlopen, and the
// few types it needs are declared here, so building needs no libsecret-dev and an app on a system
// without it still starts (the store then reports itself unavailable). Secrets are stored as hex
// text: the simple API stores strings.
#ifndef _WIN32

#include "store.h"

#include <dlfcn.h>

#include <cstdint>
#include <string>

namespace rk::keyring {

namespace {

// From libsecret's and GLib's public headers (stable ABI).
struct GError {
    uint32_t domain;
    int code;
    char *message;
};
enum SecretSchemaAttributeType { SECRET_SCHEMA_ATTRIBUTE_STRING = 0 };
struct SecretSchemaAttribute {
    const char *name;
    SecretSchemaAttributeType type;
};
enum SecretSchemaFlags { SECRET_SCHEMA_NONE = 0 };
struct SecretSchema {
    const char *name;
    SecretSchemaFlags flags;
    SecretSchemaAttribute attributes[32];
    int reserved;
    void *reserved1, *reserved2, *reserved3, *reserved4, *reserved5, *reserved6, *reserved7;
};

using StoreFn = int (*)(const SecretSchema *, const char *collection, const char *label, const char *password,
                        void *cancellable, GError **error, ...);
using LookupFn = char *(*)(const SecretSchema *, void *cancellable, GError **error, ...);
using ClearFn = int (*)(const SecretSchema *, void *cancellable, GError **error, ...);
using SearchFn = void *(*)(const SecretSchema *, int flags, void *cancellable, GError **error, ...); // GList *
using ListFreeFullFn = void (*)(void *list, void (*free)(void *));
using FreeFn = void (*)(char *);
using ServiceGetFn = void *(*)(int flags, void *cancellable, GError **error);
using UnrefFn = void (*)(void *);
using ErrorFreeFn = void (*)(GError *);

struct Library {
    void *handle = nullptr;
    std::string error;
    StoreFn store = nullptr;
    LookupFn lookup = nullptr;
    ClearFn clear = nullptr;
    FreeFn freePassword = nullptr;
    ServiceGetFn serviceGet = nullptr;
    UnrefFn unref = nullptr;
    ErrorFreeFn errorFree = nullptr;
    SearchFn search = nullptr; // libsecret 0.19+; without it a locked entry reads as missing
    ListFreeFullFn listFreeFull = nullptr;
};

const SecretSchema schema = {
    "org.ragekit.Secret",
    SECRET_SCHEMA_NONE,
    {{"service", SECRET_SCHEMA_ATTRIBUTE_STRING}, {"account", SECRET_SCHEMA_ATTRIBUTE_STRING}, {nullptr, SECRET_SCHEMA_ATTRIBUTE_STRING}},
    0, nullptr, nullptr, nullptr, nullptr, nullptr, nullptr, nullptr,
};

template <typename T>
bool find(void *handle, const char *name, T &out)
{
    out = reinterpret_cast<T>(dlsym(handle, name)); // also finds GLib's symbols: libsecret depends on it
    return out != nullptr;
}

Library &library()
{
    static Library lib = [] {
        Library l;
        l.handle = dlopen("libsecret-1.so.0", RTLD_NOW | RTLD_LOCAL);
        if (l.handle == nullptr) {
            l.error = "libsecret isn't installed (libsecret-1.so.0)";
            return l;
        }
        if (!find(l.handle, "secret_password_store_sync", l.store) || !find(l.handle, "secret_password_lookup_sync", l.lookup)
            || !find(l.handle, "secret_password_clear_sync", l.clear) || !find(l.handle, "secret_password_free", l.freePassword)
            || !find(l.handle, "secret_service_get_sync", l.serviceGet) || !find(l.handle, "g_object_unref", l.unref)
            || !find(l.handle, "g_error_free", l.errorFree)) {
            l.error = "libsecret is too old (a function is missing)";
        }
        if (!find(l.handle, "secret_password_search_sync", l.search) || !find(l.handle, "g_list_free_full", l.listFreeFull)) {
            l.search = nullptr;
        }
        return l;
    }();
    return lib;
}

/** The error's message (freed), or `fallback` when the call failed without one. */
std::string message(Library &lib, GError *error, const char *fallback)
{
    if (error == nullptr) {
        return fallback;
    }
    std::string text = error->message != nullptr ? error->message : fallback;
    lib.errorFree(error);
    return text;
}

std::string toHex(const std::string &bytes)
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

bool fromHex(const std::string &text, std::string &bytes)
{
    auto value = [](char c) {
        return c >= '0' && c <= '9' ? c - '0' : c >= 'a' && c <= 'f' ? c - 'a' + 10 : c >= 'A' && c <= 'F' ? c - 'A' + 10 : -1;
    };
    if (text.size() % 2 != 0) {
        return false;
    }
    bytes.assign(text.size() / 2, '\0');
    for (size_t i = 0; i < bytes.size(); i++) {
        int high = value(text[i * 2]);
        int low = value(text[i * 2 + 1]);
        if (high < 0 || low < 0) {
            return false;
        }
        bytes[i] = static_cast<char>(high << 4 | low);
    }
    return true;
}

} // namespace

std::string available()
{
    Library &lib = library();
    if (!lib.error.empty()) {
        return lib.error;
    }
    GError *error = nullptr;
    void *service = lib.serviceGet(0, nullptr, &error); // connects (starting the daemon if D-Bus can), unlocks nothing
    if (service == nullptr) {
        return "no Secret Service: " + message(lib, error, "unknown error");
    }
    lib.unref(service);
    if (error != nullptr) {
        lib.errorFree(error);
    }
    return "";
}

std::string set(const std::string &service, const std::string &account, const std::string &secret)
{
    Library &lib = library();
    if (!lib.error.empty()) {
        return lib.error;
    }
    GError *error = nullptr;
    std::string label = service + " (" + account + ")";
    std::string hex = toHex(secret);
    int ok = lib.store(&schema, "default", label.c_str(), hex.c_str(), nullptr, &error, "service", service.c_str(),
                       "account", account.c_str(), static_cast<const char *>(nullptr));
    for (char &c : hex) {
        *static_cast<volatile char *>(&c) = 0; // it was the secret
    }
    return ok != 0 ? message(lib, error, "") : message(lib, error, "the keyring didn't store the secret");
}

Found get(const std::string &service, const std::string &account)
{
    Found found;
    Library &lib = library();
    if (!lib.error.empty()) {
        found.error = lib.error;
        return found;
    }
    GError *error = nullptr;
    char *password = lib.lookup(&schema, nullptr, &error, "service", service.c_str(), "account", account.c_str(),
                                static_cast<const char *>(nullptr));
    if (error != nullptr) {
        if (password != nullptr) {
            lib.freePassword(password);
        }
        found.error = message(lib, error, "the keyring failed");
        return found;
    }
    if (password == nullptr) {
        // Nothing, or an entry in a locked keyring whose unlock prompt was dismissed (or couldn't
        // show): libsecret answers both with NULL. Searching without unlocking still lists locked
        // entries, so an app doesn't take "locked" for "never stored" and replace its key.
        if (lib.search != nullptr) {
            void *items = lib.search(&schema, 2 /* SECRET_SEARCH_ALL */, nullptr, &error, "service", service.c_str(), "account",
                                     account.c_str(), static_cast<const char *>(nullptr));
            if (error != nullptr) {
                found.error = message(lib, error, "the keyring failed");
            } else if (items != nullptr) {
                found.error = "the keyring is locked";
            }
            if (items != nullptr) {
                lib.listFreeFull(items, lib.unref);
            }
        }
        return found;
    }
    found.found = fromHex(password, found.secret);
    lib.freePassword(password); // wipes it
    if (!found.found) {
        found.error = "the stored secret isn't RageKit's (not hex)";
    }
    return found;
}

std::string remove(const std::string &service, const std::string &account)
{
    Library &lib = library();
    if (!lib.error.empty()) {
        return lib.error;
    }
    GError *error = nullptr;
    lib.clear(&schema, nullptr, &error, "service", service.c_str(), "account", account.c_str(), static_cast<const char *>(nullptr));
    return message(lib, error, ""); // false without an error = nothing to delete
}

} // namespace rk::keyring

#endif
