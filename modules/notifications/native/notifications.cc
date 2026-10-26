// The notifications module's library: desktop notifications over the org.freedesktop.Notifications
// D-Bus service, through GLib's GDBus, which is opened with dlopen (the few GLib types it needs are
// opaque pointers here), so an app without GLib still starts. Notify calls are synchronous on the
// caller's thread; a worker thread runs a GLib main loop that hears the service's ActionInvoked /
// NotificationClosed signals and posts them as "clicked" / "closed" events for our own ids.
#include "rk_module_notifications.h"

#ifndef _WIN32

#include <dlfcn.h>
#include <unistd.h>

#include <condition_variable>
#include <cstdint>
#include <fstream>
#include <mutex>
#include <set>
#include <string>
#include <thread>

namespace {

struct GError {
    uint32_t domain;
    int code;
    char *message;
};
using Ptr = void *;
using SignalFn = void (*)(Ptr connection, const char *sender, const char *path, const char *iface, const char *signal,
                          Ptr parameters, Ptr user_data);

struct Gio {
    void *handle = nullptr;
    std::string error;
    Ptr (*busGetSync)(int type, Ptr cancellable, GError **error) = nullptr;
    Ptr (*callSync)(Ptr connection, const char *name, const char *path, const char *iface, const char *method, Ptr params,
                    Ptr replyType, int flags, int timeout, Ptr cancellable, GError **error) = nullptr;
    Ptr (*variantNew)(const char *format, ...) = nullptr;
    void (*variantGet)(Ptr variant, const char *format, ...) = nullptr;
    void (*variantUnref)(Ptr variant) = nullptr;
    Ptr (*builderNew)(Ptr type) = nullptr;
    void (*builderAdd)(Ptr builder, const char *format, ...) = nullptr;
    void (*builderUnref)(Ptr builder) = nullptr;
    unsigned (*subscribe)(Ptr connection, const char *sender, const char *iface, const char *member, const char *path,
                          const char *arg0, int flags, SignalFn callback, Ptr user_data, Ptr destroy) = nullptr;
    void (*unsubscribe)(Ptr connection, unsigned id) = nullptr;
    Ptr (*contextNew)() = nullptr;
    void (*contextPush)(Ptr context) = nullptr;
    void (*contextPop)(Ptr context) = nullptr;
    void (*contextUnref)(Ptr context) = nullptr;
    Ptr (*loopNew)(Ptr context, int running) = nullptr;
    void (*loopRun)(Ptr loop) = nullptr;
    void (*loopQuit)(Ptr loop) = nullptr;
    void (*loopUnref)(Ptr loop) = nullptr;
    void (*invoke)(Ptr context, int (*fn)(Ptr), Ptr data) = nullptr;
    void (*objectUnref)(Ptr object) = nullptr;
    void (*errorFree)(GError *error) = nullptr;
};

template <typename T>
bool find(void *handle, const char *name, T &out)
{
    out = reinterpret_cast<T>(dlsym(handle, name)); // GLib's and GObject's symbols too: gio depends on them
    return out != nullptr;
}

Gio &gio()
{
    static Gio g = [] {
        Gio l;
        l.handle = dlopen("libgio-2.0.so.0", RTLD_NOW | RTLD_LOCAL);
        if (l.handle == nullptr) {
            l.error = "GLib isn't installed (libgio-2.0.so.0)";
            return l;
        }
        bool ok = find(l.handle, "g_bus_get_sync", l.busGetSync) && find(l.handle, "g_dbus_connection_call_sync", l.callSync)
            && find(l.handle, "g_variant_new", l.variantNew) && find(l.handle, "g_variant_get", l.variantGet)
            && find(l.handle, "g_variant_unref", l.variantUnref) && find(l.handle, "g_variant_builder_new", l.builderNew)
            && find(l.handle, "g_variant_builder_add", l.builderAdd) && find(l.handle, "g_variant_builder_unref", l.builderUnref)
            && find(l.handle, "g_dbus_connection_signal_subscribe", l.subscribe)
            && find(l.handle, "g_dbus_connection_signal_unsubscribe", l.unsubscribe) && find(l.handle, "g_main_context_new", l.contextNew)
            && find(l.handle, "g_main_context_push_thread_default", l.contextPush)
            && find(l.handle, "g_main_context_pop_thread_default", l.contextPop) && find(l.handle, "g_main_context_unref", l.contextUnref)
            && find(l.handle, "g_main_loop_new", l.loopNew) && find(l.handle, "g_main_loop_run", l.loopRun)
            && find(l.handle, "g_main_loop_quit", l.loopQuit) && find(l.handle, "g_main_loop_unref", l.loopUnref)
            && find(l.handle, "g_main_context_invoke", l.invoke)
            && find(l.handle, "g_object_unref", l.objectUnref) && find(l.handle, "g_error_free", l.errorFree);
        if (!ok) {
            l.error = "GLib is too old (a function is missing)";
        }
        return l;
    }();
    return g;
}

const char *kService = "org.freedesktop.Notifications";
const char *kPath = "/org/freedesktop/Notifications";

rk_post_fn post = nullptr;
std::mutex lock; // guards everything below
Ptr connection = nullptr;
std::set<uint32_t> ours; // ids this app showed and that aren't closed yet
std::thread worker;
Ptr loop = nullptr;
Ptr loopContext = nullptr; // the worker's: shutdown() asks it to quit the loop (never lost, see there)
thread_local std::string result;
std::string lastError;

const char *answer(std::string text)
{
    result = std::move(text);
    return result.c_str();
}

std::string take(Gio &g, GError *error, const char *fallback)
{
    if (error == nullptr) {
        return fallback;
    }
    std::string text = error->message != nullptr ? error->message : fallback;
    g.errorFree(error);
    return text;
}

/** The body is markup for servers that support it: plain text must not be read as tags. */
std::string escaped(const std::string &text)
{
    std::string out;
    for (char c : text) {
        out += c == '&' ? "&amp;" : c == '<' ? "&lt;" : c == '>' ? "&gt;" : std::string(1, c);
    }
    return out;
}

std::string appName()
{
    char path[4096];
    ssize_t len = readlink("/proc/self/exe", path, sizeof path - 1);
    if (len <= 0) {
        return "RageKit";
    }
    std::string exe(path, static_cast<size_t>(len));
    return exe.substr(exe.find_last_of('/') + 1);
}

void onSignal(Ptr, const char *, const char *, const char *, const char *signal, Ptr parameters, Ptr)
{
    Gio &g = gio();
    uint32_t id = 0;
    std::string kind;
    if (std::string(signal) == "ActionInvoked") {
        const char *action = nullptr;
        g.variantGet(parameters, "(u&s)", &id, &action);
        kind = "clicked";
    } else {
        uint32_t reason = 0;
        g.variantGet(parameters, "(uu)", &id, &reason);
        kind = "closed";
    }
    std::lock_guard<std::mutex> guard(lock);
    if (ours.count(id) == 0 || post == nullptr) {
        return; // another app's notification, or one already closed
    }
    if (kind == "closed") {
        ours.erase(id);
    }
    post("notifications", static_cast<int64_t>(id), kind.c_str(), "", 0);
}

/** Connects and starts the worker that hears the service's signals; under lock. '' or an error. */
std::string connect()
{
    if (connection != nullptr) {
        return "";
    }
    Gio &g = gio();
    if (!g.error.empty()) {
        return g.error;
    }
    GError *error = nullptr;
    Ptr bus = g.busGetSync(2 /* G_BUS_TYPE_SESSION */, nullptr, &error);
    if (bus == nullptr) {
        return "no session bus: " + take(g, error, "unknown error");
    }
    connection = bus;
    std::mutex readyLock;
    std::condition_variable readyCv;
    bool ready = false;
    // The worker gets what outlives connect() by value; the ready flag and its lock are only used
    // until it says so (it notifies while holding the lock, so connect() can't return before).
    worker = std::thread([&g, bus, &readyLock, &readyCv, &ready] {
        Ptr context = g.contextNew();
        g.contextPush(context); // signals subscribed here are delivered in this thread's loop
        unsigned invoked = g.subscribe(bus, kService, kService, "ActionInvoked", kPath, nullptr, 0, onSignal, nullptr, nullptr);
        unsigned closed = g.subscribe(bus, kService, kService, "NotificationClosed", kPath, nullptr, 0, onSignal, nullptr, nullptr);
        Ptr own = g.loopNew(context, 0);
        {
            std::lock_guard<std::mutex> guard(readyLock);
            loop = own;
            loopContext = context;
            ready = true;
            readyCv.notify_one();
        }
        g.loopRun(own);
        g.unsubscribe(bus, invoked);
        g.unsubscribe(bus, closed);
        g.contextPop(context);
        g.loopUnref(own);
        g.contextUnref(context);
    });
    std::unique_lock<std::mutex> wait(readyLock);
    readyCv.wait(wait, [&] { return ready; });
    return "";
}

void shutdown()
{
    std::thread stopping;
    {
        std::lock_guard<std::mutex> guard(lock);
        if (loop != nullptr) {
            // Quit from inside the worker's context: a g_main_loop_quit() that lands before the
            // worker reached g_main_loop_run() would be lost, and join() below would wait forever.
            gio().invoke(loopContext, [](Ptr l) -> int {
                gio().loopQuit(l);
                return 0; // G_SOURCE_REMOVE
            }, loop);
            loop = nullptr;
            loopContext = nullptr;
        }
        stopping = std::move(worker);
    }
    if (stopping.joinable()) {
        stopping.join(); // outside the lock: a signal being delivered takes it
    }
    std::lock_guard<std::mutex> guard(lock);
    if (connection != nullptr) {
        gio().objectUnref(connection);
        connection = nullptr;
    }
    ours.clear();
}

struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); } // a worker thread still running at exit would abort the process
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_notifications_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_notifications_reset(void)
{
    shutdown();
}

RKM_API const char *rkm_notifications_available(void)
{
    std::lock_guard<std::mutex> guard(lock);
    std::string error = connect();
    if (!error.empty()) {
        return answer(error);
    }
    Gio &g = gio();
    GError *failure = nullptr;
    Ptr reply = g.callSync(connection, kService, kPath, kService, "GetServerInformation", nullptr, nullptr, 0, 5000, nullptr, &failure);
    if (reply == nullptr) {
        return answer("no notification service: " + take(g, failure, "unknown error"));
    }
    g.variantUnref(reply);
    return answer("");
}

RKM_API int64_t rkm_notifications_show(const char *title, size_t title_len, const char *body, size_t body_len)
{
    std::lock_guard<std::mutex> guard(lock);
    lastError = connect();
    if (!lastError.empty()) {
        return 0;
    }
    Gio &g = gio();
    Ptr actions = g.builderNew(const_cast<char *>("as"));
    g.builderAdd(actions, "s", "default"); // clicking the notification invokes "default"
    g.builderAdd(actions, "s", "Open");
    Ptr hints = g.builderNew(const_cast<char *>("a{sv}"));
    std::string summary(title, title_len);
    std::string text = escaped(std::string(body, body_len));
    std::string app = appName();
    Ptr params = g.variantNew("(susssasa{sv}i)", app.c_str(), 0u, "", summary.c_str(), text.c_str(), actions, hints, -1);
    g.builderUnref(actions);
    g.builderUnref(hints);
    GError *failure = nullptr;
    Ptr reply = g.callSync(connection, kService, kPath, kService, "Notify", params, nullptr, 0, 5000, nullptr, &failure);
    if (reply == nullptr) {
        lastError = "the notification service failed: " + take(g, failure, "unknown error");
        return 0;
    }
    uint32_t id = 0;
    g.variantGet(reply, "(u)", &id);
    g.variantUnref(reply);
    ours.insert(id);
    return static_cast<int64_t>(id);
}

RKM_API void rkm_notifications_close(int64_t notification)
{
    std::lock_guard<std::mutex> guard(lock);
    if (connection == nullptr || ours.count(static_cast<uint32_t>(notification)) == 0) {
        return;
    }
    Gio &g = gio();
    GError *failure = nullptr;
    Ptr reply = g.callSync(connection, kService, kPath, kService, "CloseNotification",
                           g.variantNew("(u)", static_cast<uint32_t>(notification)), nullptr, 0, 5000, nullptr, &failure);
    if (reply != nullptr) {
        g.variantUnref(reply);
    } else if (failure != nullptr) {
        g.errorFree(failure);
    }
}

RKM_API const char *rkm_notifications_error(void)
{
    std::lock_guard<std::mutex> guard(lock);
    return answer(lastError);
}

} // extern "C"

#else // Windows (and anything else without GLib): not yet

extern "C" {
RKM_API void rkm_notifications_init(rk_post_fn) {}
RKM_API void rkm_notifications_reset(void) {}
RKM_API const char *rkm_notifications_available(void) { return "notifications aren't supported on this OS yet"; }
RKM_API int64_t rkm_notifications_show(const char *, size_t, const char *, size_t) { return 0; }
RKM_API void rkm_notifications_close(int64_t) {}
RKM_API const char *rkm_notifications_error(void) { return "notifications aren't supported on this OS yet"; }
}

#endif
