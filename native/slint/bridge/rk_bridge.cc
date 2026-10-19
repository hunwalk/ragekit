// libragekit_slint: open windows by screen name, push values by property id, forward actions
// to one dispatcher. Windows closed from inside their own callbacks are parked and destroyed on
// the next event-loop turn. All OS calls live here so a Nano executable stays audit-clean.
#ifndef RK_BUILDING_BRIDGE
#define RK_BUILDING_BRIDGE
#endif
#include "ragekit_slint.h"
#include "rk_instance.h"

#ifdef _WIN32
#include <windows.h>
#endif

#include <atomic>
#include <cstdlib>
#include <functional>
#include <map>
#include <mutex>
#include <memory>
#include <string>
#include <vector>

namespace {

std::map<int64_t, std::unique_ptr<rk::Instance>> windows;
std::vector<std::unique_ptr<rk::Instance>> parked;
rk_event_fn dispatcher = nullptr;
rk_module_event_fn eventDispatcher = nullptr;

// Module events posted before the first window exists wait here: invoke_from_event_loop() from a
// module's thread would otherwise set Slint's platform up on that thread (and abort the app).
std::mutex pendingLock;
std::vector<std::function<void()>> pending;
std::atomic<bool> platformReady{false};

void deliver(std::function<void()> job)
{
    {
        std::lock_guard<std::mutex> guard(pendingLock);
        if (!platformReady) {
            pending.push_back(std::move(job));
            return;
        }
    }
    slint::invoke_from_event_loop(std::move(job));
}

void platformIsReady()
{
    std::vector<std::function<void()>> jobs;
    {
        std::lock_guard<std::mutex> guard(pendingLock);
        if (platformReady) {
            return;
        }
        platformReady = true;
        jobs.swap(pending);
    }
    for (auto &job : jobs) {
        slint::invoke_from_event_loop(std::move(job));
    }
}
int64_t nextWindow = 1;

rk::Instance *find(int64_t window)
{
    auto it = windows.find(window);
    return it == windows.end() ? nullptr : it->second.get();
}

} // namespace

void rk::emit_action(int64_t window, int action, std::string_view arg)
{
    if (dispatcher != nullptr) {
        dispatcher(window, action, arg.data(), arg.size());
    }
}

void rk::emit_close(int64_t window)
{
    if (dispatcher != nullptr) {
        dispatcher(window, 0, "", 0);
    }
}

extern "C" {

RK_API void rk_set_dispatcher(rk_event_fn fn)
{
    dispatcher = fn;
}

RK_API void rk_set_event_dispatcher(rk_module_event_fn fn)
{
    eventDispatcher = fn;
}

RK_API void rk_post_event(const char *module, int64_t handle, const char *kind, const char *data, size_t data_len)
{
    // Any thread: copy everything, run on the UI thread.
    deliver([module = std::string(module), handle, kind = std::string(kind), data = std::string(data, data_len)] {
        if (eventDispatcher != nullptr) {
            eventDispatcher(module.data(), module.size(), handle, kind.data(), kind.size(), data.data(), data.size());
        }
    });
}

RK_API void rk_set_renderer(const char *renderer)
{
    const char *current = std::getenv("SLINT_BACKEND");
    if (current != nullptr && *current != '\0') {
        return;
    }
    std::string backend = std::string("winit-") + renderer;
#ifdef _WIN32
    SetEnvironmentVariableA("SLINT_BACKEND", backend.c_str()); // Slint reads the process environment block
#else
    setenv("SLINT_BACKEND", backend.c_str(), 0);
#endif
}

RK_API int64_t rk_open(const char *screen, size_t screen_len, const char *title, size_t title_len, int32_t width, int32_t height)
{
    int64_t id = nextWindow;
    auto instance = rk::create_instance(std::string_view(screen, screen_len), id);
    if (!instance) {
        return 0;
    }
    nextWindow++;
    instance->window().set_size(slint::LogicalSize(slint::Size<float>{static_cast<float>(width), static_cast<float>(height)}));
    instance->set_title(std::string_view(title, title_len));
    instance->show();
    windows.emplace(id, std::move(instance));
    platformIsReady(); // the UI thread has created a window: Slint's platform lives here now
    return id;
}

RK_API void rk_set_string(int64_t window, int32_t id, const char *value, size_t len)
{
    if (auto *instance = find(window)) {
        instance->set_string(id, std::string_view(value, len));
    }
}

RK_API void rk_set_int(int64_t window, int32_t id, int64_t value)
{
    if (auto *instance = find(window)) {
        instance->set_int(id, value);
    }
}

RK_API void rk_set_float(int64_t window, int32_t id, double value)
{
    if (auto *instance = find(window)) {
        instance->set_float(id, value);
    }
}

RK_API void rk_set_bool(int64_t window, int32_t id, int value)
{
    if (auto *instance = find(window)) {
        instance->set_bool(id, value != 0);
    }
}

RK_API void rk_set_list(int64_t window, int32_t id, const char *const *items, const size_t *lens, size_t count)
{
    if (auto *instance = find(window)) {
        std::vector<slint::SharedString> values;
        values.reserve(count);
        for (size_t i = 0; i < count; ++i) {
            values.emplace_back(std::string_view(items[i], lens[i]));
        }
        instance->set_list(id, std::move(values));
    }
}

RK_API void rk_close(int64_t window)
{
    auto it = windows.find(window);
    if (it == windows.end()) {
        return;
    }
    it->second->hide();
    parked.push_back(std::move(it->second));
    windows.erase(it);
    slint::invoke_from_event_loop([] { parked.clear(); });
}

RK_API void rk_window_minimize(int64_t window)
{
    if (auto *instance = find(window)) {
        instance->window().set_minimized(true);
    }
}

RK_API void rk_window_set_maximized(int64_t window, int maximized)
{
    if (auto *instance = find(window)) {
        instance->window().set_maximized(maximized != 0);
    }
}

RK_API void rk_run(void)
{
    slint::run_event_loop(slint::EventLoopMode::RunUntilQuit);
    rk_modules_reset(); // stop module threads (e.g. audio) while everything they use still exists
    parked.clear();
    windows.clear();
    dispatcher = nullptr;
    eventDispatcher = nullptr;
}

RK_API void rk_quit(void)
{
    slint::quit_event_loop();
}

} // extern "C"
