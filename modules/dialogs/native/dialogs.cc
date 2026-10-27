// The dialogs module's library: file dialogs through the xdg-desktop-portal FileChooser
// (org.freedesktop.portal.FileChooser), so the desktop draws its own dialog (GNOME's, KDE's, …) and
// a sandboxed app gets access to what the user picked. GLib's GDBus is opened with dlopen (the few
// GLib types it needs are opaque pointers here), so an app without GLib still starts. OpenFile /
// SaveFile return at once with a request object; a worker thread runs a GLib main loop that hears
// the requests' Response signals and posts them as "done" events for our own ids.
#include "rk_module_dialogs.h"

#ifndef _WIN32

#include <dlfcn.h>
#include <unistd.h>

#include <cstdio>
#include <cstdlib>

#include <condition_variable>
#include <cstdint>
#include <map>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

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
    const char *(*uniqueName)(Ptr connection) = nullptr;
    Ptr (*callSync)(Ptr connection, const char *name, const char *path, const char *iface, const char *method, Ptr params,
                    Ptr replyType, int flags, int timeout, Ptr cancellable, GError **error) = nullptr;
    Ptr (*variantNew)(const char *format, ...) = nullptr;
    Ptr (*newBytestring)(const char *text) = nullptr;
    void (*variantGet)(Ptr variant, const char *format, ...) = nullptr;
    Ptr (*lookupValue)(Ptr dictionary, const char *key, Ptr type) = nullptr;
    const char **(*getStrv)(Ptr variant, size_t *length) = nullptr;
    void (*variantUnref)(Ptr variant) = nullptr;
    Ptr (*builderNew)(Ptr type) = nullptr;
    void (*builderAdd)(Ptr builder, const char *format, ...) = nullptr;
    Ptr (*builderEnd)(Ptr builder) = nullptr;
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
    char *(*filenameFromUri)(const char *uri, char **hostname, GError **error) = nullptr;
    void (*free)(Ptr memory) = nullptr;
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
        bool ok = find(l.handle, "g_bus_get_sync", l.busGetSync) && find(l.handle, "g_dbus_connection_get_unique_name", l.uniqueName)
            && find(l.handle, "g_dbus_connection_call_sync", l.callSync) && find(l.handle, "g_variant_new", l.variantNew)
            && find(l.handle, "g_variant_new_bytestring", l.newBytestring) && find(l.handle, "g_variant_get", l.variantGet)
            && find(l.handle, "g_variant_lookup_value", l.lookupValue) && find(l.handle, "g_variant_get_strv", l.getStrv)
            && find(l.handle, "g_variant_unref", l.variantUnref) && find(l.handle, "g_variant_builder_new", l.builderNew)
            && find(l.handle, "g_variant_builder_add", l.builderAdd) && find(l.handle, "g_variant_builder_end", l.builderEnd)
            && find(l.handle, "g_variant_builder_unref", l.builderUnref)
            && find(l.handle, "g_dbus_connection_signal_subscribe", l.subscribe)
            && find(l.handle, "g_dbus_connection_signal_unsubscribe", l.unsubscribe) && find(l.handle, "g_main_context_new", l.contextNew)
            && find(l.handle, "g_main_context_push_thread_default", l.contextPush)
            && find(l.handle, "g_main_context_pop_thread_default", l.contextPop) && find(l.handle, "g_main_context_unref", l.contextUnref)
            && find(l.handle, "g_main_loop_new", l.loopNew) && find(l.handle, "g_main_loop_run", l.loopRun)
            && find(l.handle, "g_main_loop_quit", l.loopQuit) && find(l.handle, "g_main_loop_unref", l.loopUnref)
            && find(l.handle, "g_main_context_invoke", l.invoke) && find(l.handle, "g_filename_from_uri", l.filenameFromUri)
            && find(l.handle, "g_free", l.free) && find(l.handle, "g_object_unref", l.objectUnref)
            && find(l.handle, "g_error_free", l.errorFree);
        if (!ok) {
            l.error = "GLib is too old (a function is missing)";
        }
        return l;
    }();
    return g;
}

const char *kPortal = "org.freedesktop.portal.Desktop";
const char *kPath = "/org/freedesktop/portal/desktop";
const char *kChooser = "org.freedesktop.portal.FileChooser";

rk_post_fn post = nullptr;
std::mutex lock; // guards everything below
Ptr connection = nullptr;
std::map<std::string, int64_t> pending; // request object path => our id
int64_t nextId = 1;
std::thread worker;
Ptr loop = nullptr;
Ptr loopContext = nullptr; // the worker's: shutdown() asks it to quit the loop
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

/** Response(u code, a{sv} results) of one of our requests: 0 = chosen, 1 = cancelled, 2 = failed. */
void onResponse(Ptr, const char *, const char *path, const char *, const char *, Ptr parameters, Ptr)
{
    Gio &g = gio();
    int64_t id;
    {
        std::lock_guard<std::mutex> guard(lock);
        auto it = pending.find(path);
        if (it == pending.end() || post == nullptr) {
            return; // another app's request, or one from before a reset
        }
        id = it->second;
        pending.erase(it);
    }
    uint32_t code = 2;
    Ptr results = nullptr;
    g.variantGet(parameters, "(u@a{sv})", &code, &results);
    std::string paths;
    if (code == 0) {
        Ptr uris = g.lookupValue(results, "uris", const_cast<char *>("as"));
        if (uris != nullptr) {
            size_t n = 0;
            const char **list = g.getStrv(uris, &n);
            for (size_t i = 0; i < n; i++) {
                char *file = g.filenameFromUri(list[i], nullptr, nullptr); // file:// only; other URIs are skipped
                if (file != nullptr) {
                    paths += (paths.empty() ? "" : "\n") + std::string(file);
                    g.free(file);
                }
            }
            g.free(list);
            g.variantUnref(uris);
        }
    }
    if (results != nullptr) {
        g.variantUnref(results);
    }
    if (code == 2) {
        post("dialogs", id, "error", "the file dialog failed", 22);
    } else {
        post("dialogs", id, "done", paths.c_str(), paths.size());
    }
}

/** Connects and starts the worker that hears the requests' responses; under lock. '' or an error. */
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
    // One subscription for every request's Response, made before any request exists, so a fast
    // answer can't be missed; onResponse picks ours by object path.
    worker = std::thread([&g, bus, &readyLock, &readyCv, &ready] {
        Ptr context = g.contextNew();
        g.contextPush(context);
        unsigned response = g.subscribe(bus, kPortal, "org.freedesktop.portal.Request", "Response", nullptr, nullptr, 0,
                                        onResponse, nullptr, nullptr);
        Ptr own = g.loopNew(context, 0);
        {
            std::lock_guard<std::mutex> guard(readyLock);
            loop = own;
            loopContext = context;
            ready = true;
            readyCv.notify_one();
        }
        g.loopRun(own);
        g.unsubscribe(bus, response);
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
        stopping.join(); // outside the lock: a response being delivered takes it
    }
    std::lock_guard<std::mutex> guard(lock);
    if (connection != nullptr) {
        gio().objectUnref(connection);
        connection = nullptr;
    }
    pending.clear();
}

struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); } // a worker thread still running at exit would abort the process
} shutdownGuard;

/**
 * The app's window as the portal's parent ("x11:<id>"), so the dialog opens over it and stays in
 * front of it: the active window when it's this process's (_NET_WM_PID), through libX11 loaded at
 * runtime. '' on a Wayland-only session or when the active window is another app's.
 */
std::string parentWindow()
{
    using Display = struct _XDisplay;
    using Window = unsigned long;
    using Atom = unsigned long;
    static void *x11 = dlopen("libX11.so.6", RTLD_NOW | RTLD_LOCAL);
    const char *display = getenv("DISPLAY");
    if (x11 == nullptr || display == nullptr || *display == '\0') {
        return "";
    }
    auto open = reinterpret_cast<Display *(*)(const char *)>(dlsym(x11, "XOpenDisplay"));
    auto close = reinterpret_cast<int (*)(Display *)>(dlsym(x11, "XCloseDisplay"));
    auto root = reinterpret_cast<Window (*)(Display *)>(dlsym(x11, "XDefaultRootWindow"));
    auto intern = reinterpret_cast<Atom (*)(Display *, const char *, int)>(dlsym(x11, "XInternAtom"));
    auto property = reinterpret_cast<int (*)(Display *, Window, Atom, long, long, int, Atom, Atom *, int *, unsigned long *,
                                              unsigned long *, unsigned char **)>(dlsym(x11, "XGetWindowProperty"));
    auto release = reinterpret_cast<int (*)(void *)>(dlsym(x11, "XFree"));
    if (open == nullptr || close == nullptr || root == nullptr || intern == nullptr || property == nullptr || release == nullptr) {
        return "";
    }
    Display *d = open(nullptr);
    if (d == nullptr) {
        return "";
    }
    // A CARDINAL/WINDOW property's first item (format 32 items are longs in memory).
    auto first = [&](Window w, const char *name, unsigned long &out) {
        Atom type = 0;
        int format = 0;
        unsigned long items = 0, after = 0;
        unsigned char *data = nullptr;
        bool ok = property(d, w, intern(d, name, 0), 0, 1, 0, 0 /* AnyPropertyType */, &type, &format, &items, &after, &data) == 0
            && data != nullptr && items > 0 && format == 32;
        if (ok) {
            out = *reinterpret_cast<unsigned long *>(data);
        }
        if (data != nullptr) {
            release(data);
        }
        return ok;
    };
    std::string parent;
    unsigned long active = 0, pid = 0;
    if (first(root(d), "_NET_ACTIVE_WINDOW", active) && active != 0 && first(active, "_NET_WM_PID", pid)
        && pid == static_cast<unsigned long>(getpid())) {
        char text[32];
        snprintf(text, sizeof text, "x11:%lx", active);
        parent = text;
    }
    close(d);
    return parent;
}

std::vector<std::string> split(const std::string &text, char by)
{
    std::vector<std::string> parts;
    size_t start = 0;
    while (start <= text.size()) {
        size_t end = text.find(by, start);
        if (end == std::string::npos) {
            end = text.size();
        }
        if (end > start) {
            parts.push_back(text.substr(start, end - start));
        }
        start = end + 1;
    }
    return parts;
}

/** "Images\tpng jpg" lines → a(sa(us)) of glob patterns, both cases ("*.png", "*.PNG"). */
Ptr filterList(Gio &g, const std::string &filters)
{
    Ptr all = g.builderNew(const_cast<char *>("a(sa(us))"));
    for (const std::string &line : split(filters, '\n')) {
        size_t tab = line.find('\t');
        std::string name = line.substr(0, tab);
        Ptr patterns = g.builderNew(const_cast<char *>("a(us)"));
        for (const std::string &ext : split(tab == std::string::npos ? "" : line.substr(tab + 1), ' ')) {
            if (ext == "*") {
                g.builderAdd(patterns, "(us)", 0u, "*");
                continue;
            }
            std::string lower = ext, upper = ext;
            for (char &c : lower) c = static_cast<char>(c >= 'A' && c <= 'Z' ? c + 32 : c);
            for (char &c : upper) c = static_cast<char>(c >= 'a' && c <= 'z' ? c - 32 : c);
            g.builderAdd(patterns, "(us)", 0u, ("*." + lower).c_str());
            if (upper != lower) {
                g.builderAdd(patterns, "(us)", 0u, ("*." + upper).c_str());
            }
        }
        g.builderAdd(all, "(s@a(us))", name.c_str(), g.builderEnd(patterns));
        g.builderUnref(patterns);
    }
    Ptr list = g.builderEnd(all);
    g.builderUnref(all);
    return list;
}

} // namespace

extern "C" {

RKM_API void rkm_dialogs_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_dialogs_reset(void)
{
    shutdown();
}

RKM_API const char *rkm_dialogs_available(void)
{
    std::lock_guard<std::mutex> guard(lock);
    std::string error = connect();
    if (!error.empty()) {
        return answer(error);
    }
    Gio &g = gio();
    GError *failure = nullptr;
    Ptr reply = g.callSync(connection, kPortal, kPath, "org.freedesktop.DBus.Properties", "Get",
                           g.variantNew("(ss)", kChooser, "version"), nullptr, 0, 10000, nullptr, &failure);
    if (reply == nullptr) {
        return answer("no file chooser portal (xdg-desktop-portal): " + take(g, failure, "unknown error"));
    }
    g.variantUnref(reply);
    return answer("");
}

RKM_API int64_t rkm_dialogs_file(int64_t kind, const char *title, size_t title_len, const char *filters, size_t filters_len,
                                 int multiple, const char *file, size_t file_len, const char *folder, size_t folder_len)
{
    std::lock_guard<std::mutex> guard(lock);
    lastError = connect();
    if (!lastError.empty()) {
        return 0;
    }
    Gio &g = gio();
    int64_t id = nextId++;
    std::string token = "ragekit" + std::to_string(id);
    // The request's object path is known in advance (the portal spec says how it's made), so the
    // response finds its id even if it comes before OpenFile's reply.
    std::string sender = g.uniqueName(connection) + 1; // without the ':'
    for (char &c : sender) {
        c = c == '.' ? '_' : c;
    }
    std::string path = std::string(kPath) + "/request/" + sender + "/" + token;
    pending[path] = id;

    Ptr options = g.builderNew(const_cast<char *>("a{sv}"));
    g.builderAdd(options, "{sv}", "handle_token", g.variantNew("s", token.c_str()));
    if (kind == 2) {
        g.builderAdd(options, "{sv}", "directory", g.variantNew("b", 1));
    } else if (multiple && kind == 0) {
        g.builderAdd(options, "{sv}", "multiple", g.variantNew("b", 1));
    }
    std::string filterText(filters, filters_len);
    if (!filterText.empty() && kind != 2) {
        g.builderAdd(options, "{sv}", "filters", filterList(g, filterText));
    }
    if (kind == 1 && file_len > 0) {
        g.builderAdd(options, "{sv}", "current_name", g.variantNew("s", std::string(file, file_len).c_str()));
    }
    if (folder_len > 0) {
        g.builderAdd(options, "{sv}", "current_folder", g.newBytestring(std::string(folder, folder_len).c_str()));
    }
    std::string caption(title, title_len);
    if (caption.empty()) {
        caption = kind == 1 ? "Save" : kind == 2 ? "Choose a folder" : "Open";
    }
    std::string parent = parentWindow();
    Ptr params = g.variantNew("(ssa{sv})", parent.c_str(), caption.c_str(), options);
    g.builderUnref(options);
    GError *failure = nullptr;
    Ptr reply = g.callSync(connection, kPortal, kPath, kChooser, kind == 1 ? "SaveFile" : "OpenFile", params, nullptr, 0,
                           30000, nullptr, &failure);
    if (reply == nullptr) {
        pending.erase(path);
        lastError = "no file chooser portal (xdg-desktop-portal): " + take(g, failure, "unknown error");
        return 0;
    }
    const char *handle = nullptr;
    g.variantGet(reply, "(&o)", &handle);
    if (handle != nullptr && path != handle) {
        pending.erase(path); // an old portal that picks its own path
        pending[handle] = id;
    }
    g.variantUnref(reply);
    return id;
}

RKM_API const char *rkm_dialogs_error(void)
{
    std::lock_guard<std::mutex> guard(lock);
    return answer(lastError);
}

} // extern "C"

#endif // _WIN32: dialogs_win32.cc
