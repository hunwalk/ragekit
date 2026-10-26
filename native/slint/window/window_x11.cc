// rk::win on Linux/X11: the app moves and resizes the window itself, following the pointer.
// Handing the drag to the window manager (_NET_WM_MOVERESIZE) can't work from here: the press
// grabbed the pointer on winit's X connection, which only winit can release, so the window manager
// can't take it (checked under Openbox: its grab gets AlreadyGrabbed). So there's no WM snapping.
// libX11 is loaded at runtime (for the pointer's screen position), so apps don't depend on it.
#if !defined(_WIN32) && !defined(__APPLE__)

#include "window.h"
#include "double_click.h"

#include <algorithm>
#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <dlfcn.h>
#include <string>
#include <type_traits>
#include <unistd.h>

namespace {

// The few Xlib types and functions used, declared here instead of including X11 headers (not
// needed to build, and libX11 is optional at runtime).
using Display = struct _XDisplay;
using XWindow = unsigned long;
using Bool = int;

using Atom = unsigned long;

// XClientMessageEvent, as Xlib lays it out (for _NET_ACTIVE_WINDOW), padded to an XEvent's size.
struct ClientMessage {
    int type;
    unsigned long serial;
    Bool send_event;
    Display *display;
    XWindow window;
    Atom message_type;
    int format;
    long l[5];
    char pad[96];
};

struct Xlib {
    Display *(*XOpenDisplay)(const char *);
    XWindow (*XDefaultRootWindow)(Display *);
    Bool (*XQueryPointer)(Display *, XWindow, XWindow *, XWindow *, int *, int *, int *, int *, unsigned int *);
    // raise(): finding our window and asking the window manager to activate it.
    int (*XQueryTree)(Display *, XWindow, XWindow *, XWindow *, XWindow **, unsigned int *) = nullptr;
    Atom (*XInternAtom)(Display *, const char *, Bool) = nullptr;
    int (*XGetWindowProperty)(Display *, XWindow, Atom, long, long, Bool, Atom, Atom *, int *, unsigned long *,
                              unsigned long *, unsigned char **) = nullptr;
    int (*XSendEvent)(Display *, XWindow, Bool, long, void *) = nullptr;
    int (*XFlush)(Display *) = nullptr;
    int (*XFree)(void *) = nullptr;
    using ErrorHandler = int (*)(Display *, void *);
    ErrorHandler (*XSetErrorHandler)(ErrorHandler) = nullptr;
    int (*XSync)(Display *, Bool) = nullptr;
    Display *display = nullptr;
};

// Opened once, on first use; nullptr when libX11 or the X display isn't there, or when the app
// runs on native Wayland (prefer_x11() unsets WAYLAND_DISPLAY whenever it switches to X11): our
// windows aren't X11 windows then.
Xlib *xlib()
{
    const char *wayland = std::getenv("WAYLAND_DISPLAY");
    if (wayland != nullptr && *wayland != '\0') {
        return nullptr;
    }
    static bool tried = false;
    static Xlib lib;
    if (tried) {
        return lib.display != nullptr ? &lib : nullptr;
    }
    tried = true;
    void *handle = dlopen("libX11.so.6", RTLD_NOW | RTLD_LOCAL);
    if (handle == nullptr) {
        return nullptr;
    }
    auto load = [handle](auto &fn, const char *name) {
        fn = reinterpret_cast<std::remove_reference_t<decltype(fn)>>(dlsym(handle, name));
        return fn != nullptr;
    };
    if (!load(lib.XOpenDisplay, "XOpenDisplay") || !load(lib.XDefaultRootWindow, "XDefaultRootWindow")
        || !load(lib.XQueryPointer, "XQueryPointer")) {
        return nullptr;
    }
    if (!load(lib.XQueryTree, "XQueryTree") || !load(lib.XInternAtom, "XInternAtom") || !load(lib.XGetWindowProperty, "XGetWindowProperty")
        || !load(lib.XSendEvent, "XSendEvent") || !load(lib.XFlush, "XFlush") || !load(lib.XFree, "XFree")
        || !load(lib.XSetErrorHandler, "XSetErrorHandler") || !load(lib.XSync, "XSync")) {
        lib.XQueryTree = nullptr; // raise() isn't possible; the rest still is
    }
    lib.display = lib.XOpenDisplay(nullptr);
    return lib.display != nullptr ? &lib : nullptr;
}

/** The pointer's position on the screen. */
bool pointer(int &rootX, int &rootY)
{
    Xlib *x = xlib();
    if (x == nullptr) {
        return false;
    }
    XWindow root = x->XDefaultRootWindow(x->display), rootReturn = 0, child = 0;
    int winX = 0, winY = 0;
    unsigned int mask = 0;
    return x->XQueryPointer(x->display, root, &rootReturn, &child, &rootX, &rootY, &winX, &winY, &mask);
}

// The drag in progress: what it changes (-1 = moves; else the Edge resized), and where the pointer
// and the window were when the button went down (physical pixels).
struct Drag {
    bool active = false;
    int edge = -1;
    int pointerX = 0, pointerY = 0;
    int x = 0, y = 0, width = 0, height = 0;
} drag;

void begin(slint::Window &window, int edge)
{
    drag.active = false;
    int x = 0, y = 0;
    if (!pointer(x, y)) {
        return;
    }
    auto position = window.position();
    auto size = window.size();
    drag = Drag{true, edge, x, y, position.x, position.y, static_cast<int>(size.width), static_cast<int>(size.height)};
}

} // namespace

namespace rk::win {

void prefer_x11()
{
    const char *wayland = std::getenv("WAYLAND_DISPLAY");
    if (wayland == nullptr || *wayland == '\0') {
        return;
    }
    const char *backend = std::getenv("SLINT_BACKEND");
    const char *keep = std::getenv("RAGEKIT_WAYLAND");
    if ((backend != nullptr && std::strstr(backend, "wayland") != nullptr) || (keep != nullptr && std::strcmp(keep, "1") == 0)) {
        return;
    }
    const char *display = std::getenv("DISPLAY");
    if (display == nullptr || *display == '\0') {
        std::fprintf(stderr, "ragekit: no X11 (XWayland) display; on Wayland the app can't move its own title bar or come to the front for a link\n");
        return;
    }
    unsetenv("WAYLAND_DISPLAY");
}

void make_floating(slint::Window &)
{
}

bool double_click()
{
    static DoubleClick clicks;
    int x = 0, y = 0;
    if (!pointer(x, y)) {
        return false;
    }
    auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now().time_since_epoch()).count();
    return clicks.press(ms, x, y, 400, 4); // GTK's and Qt's defaults
}

void start_move(slint::Window &window)
{
    begin(window, -1);
}

void move(slint::Window &window)
{
    int x = 0, y = 0;
    if (drag.active && drag.edge == -1 && pointer(x, y)) {
        window.set_position(slint::PhysicalPosition({drag.x + x - drag.pointerX, drag.y + y - drag.pointerY}));
    }
}

void start_resize(slint::Window &window, Edge edge)
{
    begin(window, static_cast<int>(edge));
}

void resize(slint::Window &window)
{
    int px = 0, py = 0;
    if (!drag.active || drag.edge < 0 || !pointer(px, py)) {
        return;
    }
    constexpr int minimum = 120; // physical pixels; the window's own minimum size still applies
    auto e = static_cast<Edge>(drag.edge);
    int dx = px - drag.pointerX, dy = py - drag.pointerY;
    int x = drag.x, y = drag.y, width = drag.width, height = drag.height;
    if (e == Edge::Left || e == Edge::TopLeft || e == Edge::BottomLeft) {
        dx = std::min(dx, drag.width - minimum);
        x += dx;
        width -= dx;
    } else if (e == Edge::Right || e == Edge::TopRight || e == Edge::BottomRight) {
        width = std::max(minimum, width + dx);
    }
    if (e == Edge::Top || e == Edge::TopLeft || e == Edge::TopRight) {
        dy = std::min(dy, drag.height - minimum);
        y += dy;
        height -= dy;
    } else if (e == Edge::Bottom || e == Edge::BottomLeft || e == Edge::BottomRight) {
        height = std::max(minimum, height + dy);
    }
    window.set_size(slint::PhysicalSize({static_cast<uint32_t>(width), static_cast<uint32_t>(height)}));
    if (x != drag.x || y != drag.y) {
        window.set_position(slint::PhysicalPosition({x, y}));
    }
}

} // namespace rk::win

namespace {

/** A window property as bytes ('' if it isn't set). */
std::string property(Xlib *x, XWindow window, const char *name)
{
    Atom type = 0;
    int format = 0;
    unsigned long items = 0, after = 0;
    unsigned char *data = nullptr;
    std::string out;
    if (x->XGetWindowProperty(x->display, window, x->XInternAtom(x->display, name, 0), 0, 4096, 0, 0 /* AnyPropertyType */, &type,
                              &format, &items, &after, &data) == 0 && data != nullptr) {
        out.assign(reinterpret_cast<const char *>(data), items * (format == 32 ? sizeof(long) : format / 8));
        x->XFree(data);
    }
    return out;
}

/** This process's window titled `title`: a child of the root, or of a window manager's frame. */
XWindow find(Xlib *x, XWindow parent, const char *title, int depth)
{
    XWindow root = 0, up = 0, *children = nullptr;
    unsigned int count = 0;
    if (x->XQueryTree(x->display, parent, &root, &up, &children, &count) == 0) {
        return 0;
    }
    XWindow found = 0;
    for (unsigned int i = count; i-- > 0 && found == 0;) { // topmost first
        std::string pid = property(x, children[i], "_NET_WM_PID");
        if (pid.size() >= sizeof(long) && *reinterpret_cast<const long *>(pid.data()) == static_cast<long>(getpid())
            && property(x, children[i], "_NET_WM_NAME") == title) {
            found = children[i];
        } else if (depth > 0) {
            found = find(x, children[i], title, depth - 1);
        }
    }
    if (children != nullptr) {
        x->XFree(children);
    }
    return found;
}

} // namespace

namespace rk::win {

void raise(slint::Window &window, const char *title)
{
    window.set_minimized(false);
    if (activate_wayland(window)) {
        return;
    }
    // A startup id ("…_TIME<server time>") dates the request to the user's action, which window
    // managers' focus-stealing prevention checks.
    std::string startup = take_activation_token();
    long time = 0;
    if (auto at = startup.rfind("_TIME"); at != std::string::npos) {
        time = std::strtol(startup.c_str() + at + 5, nullptr, 10);
    }
    Xlib *x = xlib();
    if (x == nullptr || x->XQueryTree == nullptr) {
        return;
    }
    XWindow root = x->XDefaultRootWindow(x->display);
    // Windows come and go during the walk (other apps' popups): a BadWindow for one of them must
    // be skipped, not handled by Xlib's default handler, which exits the process.
    x->XSync(x->display, 0);
    auto previous = x->XSetErrorHandler([](Display *, void *) { return 0; });
    XWindow ours = find(x, root, title, 2);
    x->XSync(x->display, 0); // errors of the walk arrive while the quiet handler is still in place
    x->XSetErrorHandler(previous);
    if (ours == 0) {
        return;
    }
    ClientMessage event{};
    event.type = 33; // ClientMessage
    event.send_event = 1;
    event.display = x->display;
    event.window = ours;
    event.message_type = x->XInternAtom(x->display, "_NET_ACTIVE_WINDOW", 0);
    event.format = 32;
    event.l[0] = 2; // the request comes from a pager-like source: window managers honour it
    event.l[1] = time;
    // SubstructureRedirectMask | SubstructureNotifyMask
    x->XSendEvent(x->display, root, 0, (1L << 20) | (1L << 19), &event);
    x->XFlush(x->display);
}

} // namespace rk::win

#endif
