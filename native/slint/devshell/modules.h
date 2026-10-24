// Native modules in the dev shell: loaded at runtime and called by name through each library's
// generated rkm_<name>_call entry, so the shell never needs rebuilding for a module.
#pragma once

#include <cstdint>
#include <functional>
#include <map>
#include <string>
#include <vector>

namespace rk::dev {

class Modules {
public:
    // Runs on the module's thread; copy and hop to the UI thread.
    using EventFn = std::function<void(std::string module, int64_t handle, std::string kind, std::string data)>;

    explicit Modules(EventFn onEvent);
    bool load(const std::string &name, const std::string &path); // dlopen (LoadLibrary) + rkm_<name>_init
    void unload(const std::string &name);                      // reset + dlclose (FreeLibrary)
    void resetAll();
    bool available(const std::string &name) const;
    std::string error(const std::string &name) const;
    std::vector<std::string> names() const;
    // false: no such module/function (result holds the message)
    bool call(const std::string &function, const std::vector<std::string> &args, std::string &result);

private:
    struct Module {
        void *library = nullptr;
        std::string error;
        const char *(*call)(const char *, const char *const *, const size_t *, size_t, int *) = nullptr;
        void (*reset)() = nullptr;
    };
    std::map<std::string, Module> modules_;
};

} // namespace rk::dev
