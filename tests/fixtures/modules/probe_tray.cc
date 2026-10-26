// Shows a tray icon with a menu through the tray module's library and prints its events, until it
// got a click and a menu choice (or 20 s passed): probe_tray <library> <icon file>
// A test drives it from outside with gdbus, as a tray host would (tests/Modules/TrayTest.php).
#include <dlfcn.h>
#include <unistd.h>

#include <chrono>
#include <condition_variable>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <mutex>
#include <string>

using PostFn = void (*)(const char *, int64_t, const char *, const char *, size_t);

std::mutex lock;
std::condition_variable changed;
bool activated = false, chosen = false;

void post(const char *, int64_t handle, const char *kind, const char *data, size_t len)
{
    std::lock_guard<std::mutex> guard(lock);
    std::printf("event %lld %s %.*s\n", static_cast<long long>(handle), kind, static_cast<int>(len), data);
    std::fflush(stdout);
    activated = activated || std::strcmp(kind, "activate") == 0;
    chosen = chosen || std::strcmp(kind, "menu") == 0;
    changed.notify_all();
}

int main(int, char **argv)
{
    void *lib = dlopen(argv[1], RTLD_NOW);
    if (lib == nullptr) {
        std::printf("dlopen failed: %s\n", dlerror());
        return 1;
    }
    auto init = reinterpret_cast<void (*)(PostFn)>(dlsym(lib, "rkm_tray_init"));
    auto reset = reinterpret_cast<void (*)()>(dlsym(lib, "rkm_tray_reset"));
    auto available = reinterpret_cast<const char *(*)()>(dlsym(lib, "rkm_tray_available"));
    auto show = reinterpret_cast<int64_t (*)(const char *, size_t, const char *, size_t)>(dlsym(lib, "rkm_tray_show"));
    auto menu = reinterpret_cast<void (*)(const char *, size_t)>(dlsym(lib, "rkm_tray_menu"));
    init(post);
    std::printf("available [%s]\n", available());
    std::string icon = argv[2];
    std::string tooltip = "3 unread 'quoted'";
    std::printf("show %lld\n", static_cast<long long>(show(icon.data(), icon.size(), tooltip.data(), tooltip.size())));
    std::string items = "Open_now\n-\nQuit";
    menu(items.data(), items.size());
    std::printf("ready %d\n", static_cast<int>(getpid()));
    std::fflush(stdout);
    {
        std::unique_lock<std::mutex> guard(lock);
        changed.wait_for(guard, std::chrono::seconds(20), [] { return activated && chosen; });
    }
    reset();
    std::printf("done\n");
    return 0;
}
