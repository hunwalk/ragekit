#include "modules.h"

#ifdef _WIN32
#define WIN32_LEAN_AND_MEAN
#define NOMINMAX
#include <windows.h>
#else
#include <dlfcn.h>
#endif

#include <mutex>
#include <utility>

namespace rk::dev {

namespace {

#ifdef _WIN32
void *openLibrary(const std::string &path)
{
    int size = MultiByteToWideChar(CP_UTF8, 0, path.c_str(), -1, nullptr, 0);
    std::wstring wide(static_cast<size_t>(size), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, path.c_str(), -1, wide.data(), size);
    return LoadLibraryW(wide.c_str());
}

void *symbol(void *library, const std::string &name)
{
    return reinterpret_cast<void *>(GetProcAddress(static_cast<HMODULE>(library), name.c_str()));
}

void closeLibrary(void *library)
{
    FreeLibrary(static_cast<HMODULE>(library));
}

std::string loadError(const std::string &path)
{
    DWORD code = GetLastError();
    wchar_t *text = nullptr; // as UTF-8 below: FormatMessageA would give the ANSI code page
    FormatMessageW(FORMAT_MESSAGE_ALLOCATE_BUFFER | FORMAT_MESSAGE_FROM_SYSTEM | FORMAT_MESSAGE_IGNORE_INSERTS, nullptr, code, 0, reinterpret_cast<wchar_t *>(&text), 0, nullptr);
    std::string why = "error " + std::to_string(code);
    if (text != nullptr) {
        int len = WideCharToMultiByte(CP_UTF8, 0, text, -1, nullptr, 0, nullptr, nullptr);
        if (len > 1) {
            why.assign(static_cast<size_t>(len), '\0');
            WideCharToMultiByte(CP_UTF8, 0, text, -1, why.data(), len, nullptr, nullptr);
            why.resize(static_cast<size_t>(len - 1));
        }
        LocalFree(text);
    }
    while (!why.empty() && (why.back() == '\n' || why.back() == '\r' || why.back() == ' ')) {
        why.pop_back();
    }
    return path + ": " + why;
}
#else
void *openLibrary(const std::string &path)
{
    return dlopen(path.c_str(), RTLD_NOW | RTLD_LOCAL);
}

void *symbol(void *library, const std::string &name)
{
    return dlsym(library, name.c_str());
}

void closeLibrary(void *library)
{
    dlclose(library);
}

std::string loadError(const std::string &path)
{
    const char *why = dlerror();
    return why != nullptr ? why : "not found: " + path;
}
#endif

// rk_post_fn can't carry state: the one Modules instance's handler.
std::mutex handlerLock;
Modules::EventFn handler;

void post(const char *module, int64_t handle, const char *kind, const char *data, size_t data_len)
{
    Modules::EventFn fn;
    {
        std::lock_guard<std::mutex> guard(handlerLock);
        fn = handler;
    }
    if (fn) {
        fn(module, handle, kind, std::string(data, data_len));
    }
}

using PostFn = void (*)(const char *, int64_t, const char *, const char *, size_t);

} // namespace

Modules::Modules(EventFn onEvent)
{
    std::lock_guard<std::mutex> guard(handlerLock);
    handler = std::move(onEvent);
}

bool Modules::load(const std::string &name, const std::string &path)
{
    Module module;
    module.library = openLibrary(path);
    if (module.library == nullptr) {
        module.error = loadError(path);
        modules_[name] = module;
        return false;
    }
    module.call = reinterpret_cast<decltype(module.call)>(symbol(module.library, "rkm_" + name + "_call"));
    module.reset = reinterpret_cast<void (*)()>(symbol(module.library, "rkm_" + name + "_reset"));
    auto init = reinterpret_cast<void (*)(PostFn)>(symbol(module.library, "rkm_" + name + "_init"));
    if (module.call == nullptr || init == nullptr) {
        module.error = path + " is not a RageKit module (no rkm_" + name + "_call/_init)";
        closeLibrary(module.library);
        module.library = nullptr;
        modules_[name] = module;
        return false;
    }
    init(post);
    modules_[name] = module;
    return true;
}

void Modules::unload(const std::string &name)
{
    auto it = modules_.find(name);
    if (it == modules_.end()) {
        return;
    }
    if (it->second.library != nullptr) {
        if (it->second.reset != nullptr) {
            it->second.reset();
        }
        closeLibrary(it->second.library);
    }
    modules_.erase(it);
}

void Modules::resetAll()
{
    for (auto &[name, module] : modules_) {
        if (module.reset != nullptr) {
            module.reset();
        }
    }
}

bool Modules::available(const std::string &name) const
{
    auto it = modules_.find(name);
    return it != modules_.end() && it->second.library != nullptr;
}

std::string Modules::error(const std::string &name) const
{
    auto it = modules_.find(name);
    return it == modules_.end() ? "not enabled in project.yml (ragekit: modules:)" : it->second.error;
}

std::vector<std::string> Modules::names() const
{
    std::vector<std::string> names;
    for (const auto &[name, module] : modules_) {
        names.push_back(name);
    }
    return names;
}

bool Modules::call(const std::string &function, const std::vector<std::string> &args, std::string &result)
{
    // The module whose name is the longest prefix "<name>_" of the function.
    const std::string *best = nullptr;
    for (const auto &[name, module] : modules_) {
        if (function.rfind(name + "_", 0) == 0 && (best == nullptr || name.size() > best->size())) {
            best = &name;
        }
    }
    if (best == nullptr) {
        result = "unknown function " + function;
        return false;
    }
    Module &module = modules_[*best];
    if (module.library == nullptr) {
        result = *best + " module not available: " + module.error;
        return false;
    }
    std::vector<const char *> pointers;
    std::vector<size_t> lengths;
    for (const auto &arg : args) {
        pointers.push_back(arg.data());
        lengths.push_back(arg.size());
    }
    int ok = 0;
    result = module.call(function.c_str(), pointers.data(), lengths.data(), args.size(), &ok);
    return ok != 0;
}

} // namespace rk::dev
