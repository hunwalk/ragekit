// The tray module's library: one icon per app in the system tray, over D-Bus (Linux).
//   org.kde.StatusNotifierItem at /StatusNotifierItem: the icon (IconThemePath + IconName: the
//     image file's folder and name), the tooltip, Activate (a click).
//   com.canonical.dbusmenu at /MenuBar: the menu (GetLayout, Event "clicked").
// The item owns the name org.kde.StatusNotifierItem-<pid>-1 and registers with the
// org.kde.StatusNotifierWatcher whenever one appears (also after the desktop's shell restarts).
// GLib's GDBus is opened with dlopen; replies are written as GVariant text and parsed, which keeps
// the nested D-Bus types readable. A worker thread runs a GLib main loop for the incoming calls;
// clicks are posted as "activate" / "menu" events for handle 1.
#include "rk_module_tray.h"

#ifndef _WIN32

#include <dlfcn.h>
#include <unistd.h>

#include <condition_variable>
#include <cstdint>
#include <cstring>
#include <mutex>
#include <sstream>
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

using MethodFn = void (*)(Ptr connection, const char *sender, const char *path, const char *iface, const char *method,
                          Ptr parameters, Ptr invocation, Ptr user_data);
using GetPropertyFn = Ptr (*)(Ptr connection, const char *sender, const char *path, const char *iface, const char *property,
                              GError **error, Ptr user_data);
using NameFn = void (*)(Ptr connection, const char *name, Ptr user_data);
using AppearedFn = void (*)(Ptr connection, const char *name, const char *owner, Ptr user_data);

// GDBusInterfaceVTable, as GLib lays it out.
struct VTable {
    MethodFn method;
    GetPropertyFn getProperty;
    Ptr setProperty;
    Ptr padding[8];
};

struct Gio {
    std::string error;
    Ptr (*busGetSync)(int, Ptr, GError **) = nullptr;
    Ptr (*callSync)(Ptr, const char *, const char *, const char *, const char *, Ptr, Ptr, int, int, Ptr, GError **) = nullptr;
    Ptr (*nodeInfoNew)(const char *, GError **) = nullptr;
    Ptr (*nodeInfoInterface)(Ptr, const char *) = nullptr;
    unsigned (*registerObject)(Ptr, const char *, Ptr, const VTable *, Ptr, Ptr, GError **) = nullptr;
    int (*unregisterObject)(Ptr, unsigned) = nullptr;
    unsigned (*ownName)(Ptr, const char *, int, NameFn, NameFn, Ptr, Ptr) = nullptr;
    void (*unownName)(unsigned) = nullptr;
    unsigned (*watchName)(Ptr, const char *, int, AppearedFn, NameFn, Ptr, Ptr) = nullptr;
    void (*unwatchName)(unsigned) = nullptr;
    void (*returnValue)(Ptr, Ptr) = nullptr;
    void (*returnError)(Ptr, const char *, const char *) = nullptr;
    int (*emitSignal)(Ptr, const char *, const char *, const char *, const char *, Ptr, GError **) = nullptr;
    Ptr (*parse)(Ptr, const char *, const char *, const char **, GError **) = nullptr;
    Ptr (*variantNew)(const char *, ...) = nullptr;
    void (*variantGet)(Ptr, const char *, ...) = nullptr;
    void (*variantUnref)(Ptr) = nullptr;
    Ptr (*contextNew)() = nullptr;
    void (*contextPush)(Ptr) = nullptr;
    void (*contextPop)(Ptr) = nullptr;
    void (*contextUnref)(Ptr) = nullptr;
    Ptr (*loopNew)(Ptr, int) = nullptr;
    void (*loopRun)(Ptr) = nullptr;
    void (*loopQuit)(Ptr) = nullptr;
    void (*loopUnref)(Ptr) = nullptr;
    void (*invoke)(Ptr, int (*)(Ptr), Ptr) = nullptr;
    void (*callAsync)(Ptr, const char *, const char *, const char *, const char *, Ptr, Ptr, int, int, Ptr, Ptr, Ptr) = nullptr;
    void (*objectUnref)(Ptr) = nullptr;
    void (*errorFree)(GError *) = nullptr;
};

template <typename T>
bool find(void *handle, const char *name, T &out)
{
    out = reinterpret_cast<T>(dlsym(handle, name));
    return out != nullptr;
}

Gio &gio()
{
    static Gio g = [] {
        Gio l;
        void *h = dlopen("libgio-2.0.so.0", RTLD_NOW | RTLD_LOCAL);
        if (h == nullptr) {
            l.error = "GLib isn't installed (libgio-2.0.so.0)";
            return l;
        }
        bool ok = find(h, "g_bus_get_sync", l.busGetSync) && find(h, "g_dbus_connection_call_sync", l.callSync)
            && find(h, "g_dbus_node_info_new_for_xml", l.nodeInfoNew) && find(h, "g_dbus_node_info_lookup_interface", l.nodeInfoInterface)
            && find(h, "g_dbus_connection_register_object", l.registerObject)
            && find(h, "g_dbus_connection_unregister_object", l.unregisterObject) && find(h, "g_bus_own_name_on_connection", l.ownName)
            && find(h, "g_bus_unown_name", l.unownName) && find(h, "g_bus_watch_name_on_connection", l.watchName)
            && find(h, "g_bus_unwatch_name", l.unwatchName) && find(h, "g_dbus_method_invocation_return_value", l.returnValue)
            && find(h, "g_dbus_method_invocation_return_dbus_error", l.returnError)
            && find(h, "g_dbus_connection_emit_signal", l.emitSignal) && find(h, "g_variant_parse", l.parse)
            && find(h, "g_variant_new", l.variantNew) && find(h, "g_variant_get", l.variantGet) && find(h, "g_variant_unref", l.variantUnref)
            && find(h, "g_main_context_new", l.contextNew) && find(h, "g_main_context_push_thread_default", l.contextPush)
            && find(h, "g_main_context_pop_thread_default", l.contextPop) && find(h, "g_main_context_unref", l.contextUnref)
            && find(h, "g_main_loop_new", l.loopNew) && find(h, "g_main_loop_run", l.loopRun) && find(h, "g_main_loop_quit", l.loopQuit)
            && find(h, "g_main_loop_unref", l.loopUnref)
            && find(h, "g_main_context_invoke", l.invoke) && find(h, "g_dbus_connection_call", l.callAsync) && find(h, "g_object_unref", l.objectUnref) && find(h, "g_error_free", l.errorFree);
        if (!ok) {
            l.error = "GLib is too old (a function is missing)";
        }
        return l;
    }();
    return g;
}

const char *kItemXml = R"xml(<node><interface name="org.kde.StatusNotifierItem">
  <property name="Category" type="s" access="read"/><property name="Id" type="s" access="read"/>
  <property name="Title" type="s" access="read"/><property name="Status" type="s" access="read"/>
  <property name="WindowId" type="i" access="read"/><property name="IconName" type="s" access="read"/>
  <property name="IconThemePath" type="s" access="read"/><property name="IconPixmap" type="a(iiay)" access="read"/>
  <property name="OverlayIconName" type="s" access="read"/><property name="AttentionIconName" type="s" access="read"/>
  <property name="ToolTip" type="(sa(iiay)ss)" access="read"/><property name="ItemIsMenu" type="b" access="read"/>
  <property name="Menu" type="o" access="read"/>
  <method name="ContextMenu"><arg type="i" direction="in"/><arg type="i" direction="in"/></method>
  <method name="Activate"><arg type="i" direction="in"/><arg type="i" direction="in"/></method>
  <method name="SecondaryActivate"><arg type="i" direction="in"/><arg type="i" direction="in"/></method>
  <method name="Scroll"><arg type="i" direction="in"/><arg type="s" direction="in"/></method>
  <signal name="NewIcon"/><signal name="NewToolTip"/><signal name="NewTitle"/><signal name="NewStatus"><arg type="s"/></signal>
</interface></node>)xml";

const char *kMenuXml = R"xml(<node><interface name="com.canonical.dbusmenu">
  <property name="Version" type="u" access="read"/><property name="TextDirection" type="s" access="read"/>
  <property name="Status" type="s" access="read"/><property name="IconThemePath" type="as" access="read"/>
  <method name="GetLayout"><arg type="i" direction="in"/><arg type="i" direction="in"/><arg type="as" direction="in"/>
    <arg type="u" direction="out"/><arg type="(ia{sv}av)" direction="out"/></method>
  <method name="GetGroupProperties"><arg type="ai" direction="in"/><arg type="as" direction="in"/><arg type="a(ia{sv})" direction="out"/></method>
  <method name="GetProperty"><arg type="i" direction="in"/><arg type="s" direction="in"/><arg type="v" direction="out"/></method>
  <method name="Event"><arg type="i" direction="in"/><arg type="s" direction="in"/><arg type="v" direction="in"/><arg type="u" direction="in"/></method>
  <method name="EventGroup"><arg type="a(isvu)" direction="in"/><arg type="ai" direction="out"/></method>
  <method name="AboutToShow"><arg type="i" direction="in"/><arg type="b" direction="out"/></method>
  <method name="AboutToShowGroup"><arg type="ai" direction="in"/><arg type="ai" direction="out"/><arg type="ai" direction="out"/></method>
  <signal name="LayoutUpdated"><arg type="u"/><arg type="i"/></signal>
</interface></node>)xml";

rk_post_fn post = nullptr;

// What the item shows; guarded by `lock` (the worker reads it to answer the host's calls).
std::mutex lock;
std::string iconDir, iconName, tooltip, title;
std::vector<std::string> menu; // labels in order; "-" = separator; item ids are index + 1
unsigned revision = 1;
std::string lastError;

Ptr connection = nullptr;
Ptr loop = nullptr;
Ptr loopContext = nullptr; // the worker's: shutdown() asks it to quit the loop (never lost, see there)
std::thread worker;
thread_local std::string result;

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

/** A string in GVariant's text format. */
std::string quote(const std::string &text)
{
    std::string out = "'";
    for (char c : text) {
        if (c == '\'' || c == '\\') {
            out += '\\';
            out += c;
        } else if (c == '\n') {
            out += "\\n";
        } else if (c == '\r' || c == '\t') {
            out += ' ';
        } else {
            out += c;
        }
    }
    return out + "'";
}

Ptr parsed(const char *type, const std::string &text)
{
    Gio &g = gio();
    GError *error = nullptr;
    Ptr value = g.parse(const_cast<char *>(type), text.c_str(), nullptr, nullptr, &error);
    if (value == nullptr && error != nullptr) {
        g.errorFree(error);
    }
    return value;
}

/** A label as dbusmenu shows it: a single _ marks a mnemonic, so a literal one is doubled. */
std::string label(const std::string &text)
{
    std::string out;
    for (char c : text) {
        out += c == '_' ? std::string("__") : std::string(1, c);
    }
    return out;
}

/** One menu item's properties (id = index + 1), under lock. */
std::string itemProperties(size_t index)
{
    if (menu[index] == "-") {
        return "{'type': <'separator'>}";
    }
    return "{'label': <" + quote(label(menu[index])) + ">, 'enabled': <true>, 'visible': <true>}";
}

std::string layout()
{
    std::lock_guard<std::mutex> guard(lock);
    std::ostringstream out;
    out << "(uint32 " << revision << ", (0, {'children-display': <'submenu'>}, ";
    if (menu.empty()) {
        out << "@av []";
    } else {
        out << '[';
        for (size_t i = 0; i < menu.size(); i++) {
            out << (i > 0 ? ", " : "") << "<(" << i + 1 << ", " << itemProperties(i) << ", @av [])>";
        }
        out << ']';
    }
    out << "))";
    return out.str();
}

void onItemMethod(Ptr, const char *, const char *, const char *, const char *method, Ptr, Ptr invocation, Ptr)
{
    Gio &g = gio();
    std::string name = method;
    if ((name == "Activate" || name == "SecondaryActivate") && post != nullptr) {
        post("tray", 1, "activate", "", 0);
    }
    g.returnValue(invocation, nullptr);
}

Ptr onItemProperty(Ptr, const char *, const char *, const char *, const char *property, GError **, Ptr)
{
    std::string name = property;
    std::lock_guard<std::mutex> guard(lock);
    if (name == "Category") return parsed("s", "'ApplicationStatus'");
    if (name == "Id") return parsed("s", quote(title));
    if (name == "Title") return parsed("s", quote(title));
    if (name == "Status") return parsed("s", "'Active'");
    if (name == "WindowId") return parsed("i", "0");
    if (name == "IconName") return parsed("s", quote(iconName));
    if (name == "IconThemePath") return parsed("s", quote(iconDir));
    if (name == "IconPixmap") return parsed("a(iiay)", "@a(iiay) []");
    if (name == "ToolTip") return parsed("(sa(iiay)ss)", "('', @a(iiay) [], " + quote(tooltip) + ", '')");
    if (name == "ItemIsMenu") return parsed("b", "false");
    if (name == "Menu") return parsed("o", "'/MenuBar'");
    return parsed("s", "''"); // OverlayIconName, AttentionIconName
}

void onMenuMethod(Ptr, const char *, const char *, const char *, const char *method, Ptr parameters, Ptr invocation, Ptr)
{
    Gio &g = gio();
    std::string name = method;
    auto reply = [&](const char *type, const std::string &text) {
        Ptr value = parsed(type, text);
        if (value == nullptr) {
            g.returnError(invocation, "org.ragekit.Tray.Error", "couldn't build the reply");
            return;
        }
        g.returnValue(invocation, value);
        g.variantUnref(value);
    };
    if (name == "GetLayout") {
        reply("(u(ia{sv}av))", layout());
    } else if (name == "GetGroupProperties") {
        std::lock_guard<std::mutex> guard(lock);
        std::ostringstream out;
        out << '(';
        if (menu.empty()) {
            out << "@a(ia{sv}) []";
        } else {
            out << '[';
            for (size_t i = 0; i < menu.size(); i++) {
                out << (i > 0 ? ", " : "") << '(' << i + 1 << ", " << itemProperties(i) << ')';
            }
            out << ']';
        }
        out << ",)";
        reply("(a(ia{sv}))", out.str());
    } else if (name == "GetProperty") {
        int id = 0;
        const char *property = nullptr;
        g.variantGet(parameters, "(i&s)", &id, &property);
        std::lock_guard<std::mutex> guard(lock);
        std::string value = "<''>";
        if (id >= 1 && static_cast<size_t>(id) <= menu.size() && std::string(property) == "label" && menu[id - 1] != "-") {
            value = "<" + quote(label(menu[id - 1])) + ">";
        }
        reply("(v)", "(" + value + ",)");
    } else if (name == "Event") {
        int id = 0;
        const char *event = nullptr;
        Ptr data = nullptr;
        unsigned timestamp = 0;
        g.variantGet(parameters, "(i&svu)", &id, &event, &data, &timestamp);
        if (data != nullptr) {
            g.variantUnref(data);
        }
        bool clicked = std::string(event) == "clicked";
        {
            std::lock_guard<std::mutex> guard(lock);
            clicked = clicked && id >= 1 && static_cast<size_t>(id) <= menu.size() && menu[id - 1] != "-";
        }
        if (clicked && post != nullptr) {
            std::string index = std::to_string(id - 1);
            post("tray", 1, "menu", index.data(), index.size());
        }
        g.returnValue(invocation, nullptr);
    } else if (name == "EventGroup") {
        reply("(ai)", "(@ai [],)"); // hosts use Event one item at a time in practice
    } else if (name == "AboutToShow") {
        reply("(b)", "(false,)");
    } else if (name == "AboutToShowGroup") {
        reply("(aiai)", "(@ai [], @ai [])");
    } else {
        g.returnError(invocation, "org.freedesktop.DBus.Error.UnknownMethod", "unknown method");
    }
}

Ptr onMenuProperty(Ptr, const char *, const char *, const char *, const char *property, GError **, Ptr)
{
    std::string name = property;
    if (name == "Version") return parsed("u", "uint32 3");
    if (name == "TextDirection") return parsed("s", "'ltr'");
    if (name == "Status") return parsed("s", "'normal'");
    return parsed("as", "@as []");
}

std::string serviceName()
{
    return "org.kde.StatusNotifierItem-" + std::to_string(getpid()) + "-1";
}

void registerWithWatcher(Ptr connection, const char *, const char *, Ptr)
{
    Gio &g = gio();
    std::string service = serviceName();
    // Asynchronous, with no callback: this thread must stay free to answer the watcher, which may
    // read the item's properties before it replies.
    g.callAsync(connection, "org.kde.StatusNotifierWatcher", "/StatusNotifierWatcher", "org.kde.StatusNotifierWatcher",
                "RegisterStatusNotifierItem", g.variantNew("(s)", service.c_str()), nullptr, 0, 5000, nullptr, nullptr, nullptr);
}

void emit(const char *path, const char *iface, const char *signal, Ptr params)
{
    std::lock_guard<std::mutex> guard(lock); // shutdown() may be dropping the connection
    if (connection != nullptr) {
        gio().emitSignal(connection, nullptr, path, iface, signal, params, nullptr); // doesn't wait for anyone
    } else if (params != nullptr) {
        gio().variantUnref(params);
    }
}

/** Starts the worker that serves the item and the menu; under lock. '' or an error. */
std::string start()
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
    worker = std::thread([&g, bus, &readyLock, &readyCv, &ready] {
        static const VTable item = {onItemMethod, onItemProperty, nullptr, {}};
        static const VTable menuTable = {onMenuMethod, onMenuProperty, nullptr, {}};
        Ptr context = g.contextNew();
        g.contextPush(context); // the host's calls are answered in this thread's loop
        Ptr itemInfo = g.nodeInfoNew(kItemXml, nullptr);
        Ptr menuInfo = g.nodeInfoNew(kMenuXml, nullptr);
        unsigned itemId = g.registerObject(bus, "/StatusNotifierItem", g.nodeInfoInterface(itemInfo, "org.kde.StatusNotifierItem"), &item,
                                           nullptr, nullptr, nullptr);
        unsigned menuId = g.registerObject(bus, "/MenuBar", g.nodeInfoInterface(menuInfo, "com.canonical.dbusmenu"), &menuTable,
                                           nullptr, nullptr, nullptr);
        std::string service = serviceName();
        unsigned owned = g.ownName(bus, service.c_str(), 0, nullptr, nullptr, nullptr, nullptr);
        // Register now if a watcher is there, and again whenever one (re)appears.
        unsigned watched = g.watchName(bus, "org.kde.StatusNotifierWatcher", 0, registerWithWatcher, nullptr, nullptr, nullptr);
        Ptr own = g.loopNew(context, 0);
        {
            std::lock_guard<std::mutex> guard(readyLock);
            loop = own;
            loopContext = context;
            ready = true;
            readyCv.notify_one();
        }
        g.loopRun(own);
        g.unwatchName(watched);
        g.unownName(owned);
        g.unregisterObject(bus, itemId);
        g.unregisterObject(bus, menuId);
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
        stopping.join(); // outside the lock: an answer being built takes it
    }
    std::lock_guard<std::mutex> guard(lock);
    if (connection != nullptr) {
        gio().objectUnref(connection);
        connection = nullptr;
    }
    menu.clear();
    revision = 1;
}

struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); }
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_tray_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_tray_reset(void)
{
    shutdown();
}

RKM_API const char *rkm_tray_available(void)
{
    Gio &g = gio();
    if (!g.error.empty()) {
        return answer(g.error);
    }
    GError *error = nullptr;
    Ptr bus = g.busGetSync(2, nullptr, &error);
    if (bus == nullptr) {
        return answer("no session bus: " + take(g, error, "unknown error"));
    }
    Ptr reply = g.callSync(bus, "org.freedesktop.DBus", "/org/freedesktop/DBus", "org.freedesktop.DBus", "NameHasOwner",
                           g.variantNew("(s)", "org.kde.StatusNotifierWatcher"), nullptr, 0, 5000, nullptr, &error);
    int has = 0;
    if (reply != nullptr) {
        g.variantGet(reply, "(b)", &has);
        g.variantUnref(reply);
    } else if (error != nullptr) {
        g.errorFree(error);
    }
    g.objectUnref(bus);
    return answer(has != 0 ? "" : "no tray on this desktop (no StatusNotifierWatcher; on GNOME, the AppIndicator extension provides one)");
}

RKM_API int64_t rkm_tray_show(const char *icon, size_t icon_len, const char *text, size_t text_len)
{
    std::string path(icon, icon_len);
    size_t slash = path.find_last_of('/');
    size_t dot = path.find_last_of('.');
    std::string dir = slash == std::string::npos ? "." : path.substr(0, slash);
    std::string base = path.substr(slash == std::string::npos ? 0 : slash + 1);
    if (dot != std::string::npos && (slash == std::string::npos || dot > slash)) {
        base = base.substr(0, base.size() - (path.size() - dot));
    }
    std::string exe(4096, '\0');
    ssize_t n = readlink("/proc/self/exe", exe.data(), exe.size() - 1);
    exe.resize(n > 0 ? static_cast<size_t>(n) : 0);
    bool changed;
    {
        std::lock_guard<std::mutex> guard(lock);
        lastError = start();
        if (!lastError.empty()) {
            return 0;
        }
        changed = iconDir != dir || iconName != base;
        iconDir = dir;
        iconName = base;
        tooltip.assign(text, text_len);
        title = exe.substr(exe.find_last_of('/') + 1);
    }
    if (changed) {
        emit("/StatusNotifierItem", "org.kde.StatusNotifierItem", "NewIcon", nullptr);
    }
    emit("/StatusNotifierItem", "org.kde.StatusNotifierItem", "NewToolTip", nullptr);
    return 1;
}

RKM_API void rkm_tray_tooltip(const char *text, size_t text_len)
{
    {
        std::lock_guard<std::mutex> guard(lock);
        tooltip.assign(text, text_len);
    }
    emit("/StatusNotifierItem", "org.kde.StatusNotifierItem", "NewToolTip", nullptr);
}

RKM_API void rkm_tray_menu(const char *items, size_t items_len)
{
    unsigned now;
    {
        std::lock_guard<std::mutex> guard(lock);
        menu.clear();
        std::istringstream in(std::string(items, items_len));
        std::string label;
        while (std::getline(in, label, '\n')) {
            if (!label.empty()) {
                menu.push_back(label);
            }
        }
        now = ++revision;
    }
    emit("/MenuBar", "com.canonical.dbusmenu", "LayoutUpdated", gio().variantNew("(ui)", now, 0));
}

RKM_API void rkm_tray_remove(void)
{
    shutdown();
}

RKM_API const char *rkm_tray_error(void)
{
    std::lock_guard<std::mutex> guard(lock);
    return answer(lastError);
}

} // extern "C"

#else // Windows (and anything else without D-Bus): not yet

extern "C" {
RKM_API void rkm_tray_init(rk_post_fn) {}
RKM_API void rkm_tray_reset(void) {}
RKM_API const char *rkm_tray_available(void) { return "the tray isn't supported on this OS yet"; }
RKM_API int64_t rkm_tray_show(const char *, size_t, const char *, size_t) { return 0; }
RKM_API void rkm_tray_tooltip(const char *, size_t) {}
RKM_API void rkm_tray_menu(const char *, size_t) {}
RKM_API void rkm_tray_remove(void) {}
RKM_API const char *rkm_tray_error(void) { return "the tray isn't supported on this OS yet"; }
}

#endif
