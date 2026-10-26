// Drives the notifications module's library against tests/fixtures/notifications/fake_server.py
// (inside a throwaway D-Bus session) and prints what happens, one line per step or event:
//   probe_notifications <library> [available]   (available: only the availability line)
#include <dlfcn.h>

#include <chrono>
#include <condition_variable>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

using PostFn = void (*)(const char *, int64_t, const char *, const char *, size_t);

std::mutex lock;
std::condition_variable changed;
std::vector<std::string> events;

void post(const char *module, int64_t handle, const char *kind, const char *, size_t)
{
    std::lock_guard<std::mutex> guard(lock);
    events.push_back(std::string(module) + " " + std::to_string(handle) + " " + kind);
    std::printf("event %s %lld %s\n", module, static_cast<long long>(handle), kind);
    std::fflush(stdout);
    changed.notify_all();
}

bool waitFor(const std::string &event)
{
    std::unique_lock<std::mutex> guard(lock);
    return changed.wait_for(guard, std::chrono::seconds(5), [&] {
        for (const auto &e : events) {
            if (e == event) {
                return true;
            }
        }
        return false;
    });
}

int main(int, char **argv)
{
    void *lib = dlopen(argv[1], RTLD_NOW);
    if (lib == nullptr) {
        std::printf("dlopen failed: %s\n", dlerror());
        return 1;
    }
    auto init = reinterpret_cast<void (*)(PostFn)>(dlsym(lib, "rkm_notifications_init"));
    auto reset = reinterpret_cast<void (*)()>(dlsym(lib, "rkm_notifications_reset"));
    auto available = reinterpret_cast<const char *(*)()>(dlsym(lib, "rkm_notifications_available"));
    auto show = reinterpret_cast<int64_t (*)(const char *, size_t, const char *, size_t)>(dlsym(lib, "rkm_notifications_show"));
    auto close = reinterpret_cast<void (*)(int64_t)>(dlsym(lib, "rkm_notifications_close"));
    auto error = reinterpret_cast<const char *(*)()>(dlsym(lib, "rkm_notifications_error"));
    init(post);
    std::printf("available [%s]\n", available());
    if (argv[2] != nullptr && std::string(argv[2]) == "available") {
        reset();
        return 0;
    }
    std::string body = "a <b> & c";
    int64_t first = show("Bob", 3, body.data(), body.size());
    std::printf("show %lld [%s]\n", static_cast<long long>(first), error());
    std::fflush(stdout);
    std::string click = "gdbus call --session --dest org.freedesktop.Notifications --object-path /org/freedesktop/Notifications"
                        " --method org.ragekit.Test.Click " + std::to_string(first) + " >/dev/null";
    std::system(click.c_str());
    std::printf("clicked %s\n", waitFor("notifications " + std::to_string(first) + " clicked") ? "yes" : "no");
    std::printf("closed-after-click %s\n", waitFor("notifications " + std::to_string(first) + " closed") ? "yes" : "no");
    int64_t second = show("Cyd", 3, "", 0);
    std::printf("show %lld\n", static_cast<long long>(second));
    close(second);
    std::printf("closed-by-app %s\n", waitFor("notifications " + std::to_string(second) + " closed") ? "yes" : "no");
    std::fflush(stdout);
    reset(); // stops the worker thread
    int64_t third = show("Dan", 3, "", 0); // works again after a reset
    std::printf("show-after-reset %lld\n", static_cast<long long>(third));
    reset();
    std::printf("done\n");
    return 0;
}
