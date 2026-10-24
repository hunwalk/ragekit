// libragekit_slint: open windows by screen name, push values by property id, forward actions
// to one dispatcher. Windows closed from inside their own callbacks are parked and destroyed on
// the next event-loop turn. All OS calls live here so a Nano executable stays audit-clean.
#ifndef RK_BUILDING_BRIDGE
#define RK_BUILDING_BRIDGE
#endif
#include "ragekit_slint.h"
#include "rk_instance.h"
#include "window.h"

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
std::map<int64_t, bool> maximized; // last maximised state reported to PHP, per frameless window

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

void rk::window_command(int64_t window, slint::Window &w, std::string_view command)
{
    static constexpr std::string_view edges[] = {"top-left", "top", "top-right", "right", "bottom-right", "bottom", "bottom-left", "left"};
    if (command == "move-start") {
        if (rk::win::double_click()) {
            w.set_maximized(!w.is_maximized());
        } else {
            rk::win::start_move(w);
        }
    } else if (command == "move") {
        rk::win::move(w);
    } else if (command == "resize-move") {
        rk::win::resize(w);
    } else if (command == "minimize") {
        w.set_minimized(true);
    } else if (command == "toggle-maximize") {
        w.set_maximized(!w.is_maximized()); // the resize that follows reports the new state
    } else if (command == "close") {
        rk::emit_close(window);
    } else if (command == "resized") {
        // Also after the OS maximised or restored the window (snap, Win+Up, the WM's double-click).
        bool now = w.is_maximized();
        auto [it, added] = maximized.try_emplace(window, false);
        if (it->second != now) {
            it->second = now;
            if (auto *instance = find(window)) {
                instance->set_maximized(now);
            }
            rk::emit_action(window, -1, now ? "1" : "0");
        }
    } else if (command.substr(0, 7) == "resize-") {
        for (int i = 0; i < 8; ++i) {
            if (command.substr(7) == edges[i]) {
                rk::win::start_resize(w, static_cast<rk::win::Edge>(i));
            }
        }
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
    if (rk::any_frameless()) {
        rk::win::prefer_x11(); // before Slint starts (the first window)
    }
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
    if (instance->frameless()) {
        rk::win::make_floating(instance->window());
    }
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

RK_API void rk_set_image_asset(int64_t window, int32_t id, int32_t index)
{
    if (auto *instance = find(window)) {
        instance->set_image(id, instance->asset(index).value_or(slint::Image()));
    }
}

RK_API int rk_set_image_file(int64_t window, int32_t id, const char *path, size_t len)
{
    auto *instance = find(window);
    if (instance == nullptr) {
        return 1;
    }
    if (len == 0) {
        instance->set_image(id, slint::Image());
        return 1;
    }
    // Slint takes UTF-8 paths on every platform. An image it can't decode has no size.
    auto image = slint::Image::load_from_path(slint::SharedString(std::string_view(path, len)));
    bool loaded = image.size().width > 0;
    instance->set_image(id, loaded ? image : slint::Image());
    return loaded ? 1 : 0;
}

RK_API void rk_close(int64_t window)
{
    auto it = windows.find(window);
    if (it == windows.end()) {
        return;
    }
    it->second->hide();
    maximized.erase(window);
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
