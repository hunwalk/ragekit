// Native modules: their libraries are loaded at runtime from the executable's folder, so an app
// starts without them (calls then report the module unavailable) and they can be swapped without
// rebuilding the app. Kept free of Slint so it can be tested on its own.
#ifndef RK_BUILDING_BRIDGE
#define RK_BUILDING_BRIDGE
#endif
#include "ragekit_slint.h"

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <filesystem>
#include <map>
#include <mutex>
#include <string>
#include <vector>

#ifdef _WIN32 // Windows: untested
#include <windows.h>
#else
#include <dlfcn.h>
#include <unistd.h>
#endif

namespace {

struct Loaded {
    void *library = nullptr;
    std::string error;
};

std::mutex lock;
std::map<std::string, Loaded> modules;  // by name; never erased, so error strings stay valid
std::vector<void *> handles(1, nullptr); // module id => library (id 0 = none)
std::map<std::string, std::vector<std::string>> expected; // name => symbols the app needs

/** The executable's full path (UTF-8). */
std::string exePath()
{
#ifdef _WIN32 // Windows: untested
    std::vector<wchar_t> wide(32768);
    DWORD n = GetModuleFileNameW(nullptr, wide.data(), static_cast<DWORD>(wide.size()));
    int bytes = WideCharToMultiByte(CP_UTF8, 0, wide.data(), static_cast<int>(n), nullptr, 0, nullptr, nullptr);
    std::string path(bytes, '\0');
    WideCharToMultiByte(CP_UTF8, 0, wide.data(), static_cast<int>(n), path.data(), bytes, nullptr, nullptr);
    return path;
#else
    std::vector<char> buffer(4096);
    ssize_t n = readlink("/proc/self/exe", buffer.data(), buffer.size() - 1);
    return std::string(buffer.data(), n > 0 ? static_cast<size_t>(n) : 0);
#endif
}

/** The user's cache folder: XDG_CACHE_HOME or ~/.cache, %LOCALAPPDATA%, ~/Library/Caches. */
std::string userCacheDir()
{
#ifdef _WIN32 // Windows: untested
    std::vector<wchar_t> wide(32768);
    DWORD n = GetEnvironmentVariableW(L"LOCALAPPDATA", wide.data(), static_cast<DWORD>(wide.size()));
    if (n == 0 || n >= wide.size()) {
        return ".";
    }
    int bytes = WideCharToMultiByte(CP_UTF8, 0, wide.data(), static_cast<int>(n), nullptr, 0, nullptr, nullptr);
    std::string path(bytes, '\0');
    WideCharToMultiByte(CP_UTF8, 0, wide.data(), static_cast<int>(n), path.data(), bytes, nullptr, nullptr);
    return path;
#else
    const char *home = std::getenv("HOME");
    std::string base = home != nullptr ? home : ".";
#  ifdef __APPLE__
    return base + "/Library/Caches";
#  else
    const char *xdg = std::getenv("XDG_CACHE_HOME");
    return xdg != nullptr && xdg[0] != '\0' ? std::string(xdg) : base + "/.cache";
#  endif
#endif
}

std::string appDir()
{
    std::string path = exePath();
    auto slash = path.find_last_of("\\/");
    return slash == std::string::npos ? std::string(".") : path.substr(0, slash);
}

} // namespace

extern "C" {

RK_API size_t rk_app_dir(char *buffer, size_t size)
{
    std::string dir = appDir();
    size_t n = dir.size() < size ? dir.size() : size;
    std::memcpy(buffer, dir.data(), n);
    return n;
}

RK_API size_t rk_cache_dir(char *buffer, size_t size)
{
    std::string exe = exePath();
    auto slash = exe.find_last_of("\\/");
    std::string name = slash == std::string::npos ? exe : exe.substr(slash + 1);
    if (name.size() > 4 && (name.compare(name.size() - 4, 4, ".exe") == 0 || name.compare(name.size() - 4, 4, ".EXE") == 0)) {
        name.resize(name.size() - 4);
    }
    std::string dir = userCacheDir() + "/" + (name.empty() ? std::string("ragekit") : name) + "/images";
    std::error_code ignored;
    std::filesystem::create_directories(std::filesystem::path(std::u8string(dir.begin(), dir.end())), ignored);
    size_t n = dir.size() < size ? dir.size() : size;
    std::memcpy(buffer, dir.data(), n);
    return n;
}

RK_API void rk_print_error(const char *text, size_t len)
{
    std::fwrite(text, 1, len, stderr);
    std::fputc('\n', stderr);
    std::fflush(stderr);
}

RK_API void rk_module_expect(const char *name, const char *symbols)
{
    std::lock_guard<std::mutex> guard(lock);
    std::vector<std::string> list;
    std::string text = symbols;
    for (size_t start = 0; start < text.size();) {
        size_t end = text.find('\n', start);
        if (end == std::string::npos) {
            end = text.size();
        }
        if (end > start) {
            list.push_back(text.substr(start, end - start));
        }
        start = end + 1;
    }
    expected[name] = list;
}

RK_API int64_t rk_module_open(const char *name)
{
    std::lock_guard<std::mutex> guard(lock);
    auto it = modules.find(name);
    if (it == modules.end()) {
        const char *overridden = std::getenv("RAGEKIT_MODULE_DIR");
        std::string dir = overridden != nullptr && *overridden != '\0' ? overridden : appDir();
        Loaded loaded;
#ifdef _WIN32
        std::string file = dir + "\\rk_module_" + name + ".dll";
        std::wstring wide(MultiByteToWideChar(CP_UTF8, 0, file.c_str(), -1, nullptr, 0), L'\0');
        MultiByteToWideChar(CP_UTF8, 0, file.c_str(), -1, wide.data(), static_cast<int>(wide.size()));
        loaded.library = LoadLibraryW(wide.c_str());
        if (loaded.library == nullptr) {
            loaded.error = "not found: " + file;
        }
#else
        std::string file = dir + "/librk_module_" + name + ".so";
        loaded.library = dlopen(file.c_str(), RTLD_NOW | RTLD_LOCAL);
        if (loaded.library == nullptr) {
            const char *why = dlerror();
            loaded.error = why != nullptr ? why : ("not found: " + file);
        }
#endif
        if (loaded.library != nullptr) {
            for (const auto &symbol : expected[name]) {
#ifdef _WIN32
                bool found = GetProcAddress(static_cast<HMODULE>(loaded.library), symbol.c_str()) != nullptr;
#else
                bool found = dlsym(loaded.library, symbol.c_str()) != nullptr;
#endif
                if (!found) {
                    loaded.error = file + " lacks " + symbol + " (built for a different module.yml?)";
#ifdef _WIN32
                    FreeLibrary(static_cast<HMODULE>(loaded.library));
#else
                    dlclose(loaded.library);
#endif
                    loaded.library = nullptr;
                    break;
                }
            }
        }
        it = modules.emplace(name, loaded).first;
    }
    if (it->second.library == nullptr) {
        return 0;
    }
    for (size_t id = 1; id < handles.size(); ++id) {
        if (handles[id] == it->second.library) {
            return static_cast<int64_t>(id);
        }
    }
    handles.push_back(it->second.library);
    return static_cast<int64_t>(handles.size() - 1);
}

RK_API void rk_modules_reset(void)
{
    std::vector<std::pair<std::string, void *>> loaded;
    {
        std::lock_guard<std::mutex> guard(lock);
        for (const auto &[name, module] : modules) {
            if (module.library != nullptr) {
                loaded.emplace_back(name, module.library);
            }
        }
    }
    for (const auto &[name, library] : loaded) {
        std::string symbol = "rkm_" + name + "_reset";
#ifdef _WIN32
        auto reset = reinterpret_cast<void (*)()>(GetProcAddress(static_cast<HMODULE>(library), symbol.c_str()));
#else
        auto reset = reinterpret_cast<void (*)()>(dlsym(library, symbol.c_str()));
#endif
        if (reset != nullptr) {
            reset();
        }
    }
}

RK_API const char *rk_module_error(const char *name)
{
    std::lock_guard<std::mutex> guard(lock);
    auto it = modules.find(name);
    return it == modules.end() ? "" : it->second.error.c_str();
}

RK_API void *rk_module_symbol(int64_t module, const char *symbol)
{
    std::lock_guard<std::mutex> guard(lock);
    if (module <= 0 || static_cast<size_t>(module) >= handles.size()) {
        return nullptr;
    }
#ifdef _WIN32
    return reinterpret_cast<void *>(GetProcAddress(static_cast<HMODULE>(handles[module]), symbol));
#else
    return dlsym(handles[module], symbol);
#endif
}

} // extern "C"
