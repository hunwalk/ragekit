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
#include <type_traits>

namespace {

// The few Xlib types and functions used, declared here instead of including X11 headers (not
// needed to build, and libX11 is optional at runtime).
using Display = struct _XDisplay;
using XWindow = unsigned long;
using Bool = int;

struct Xlib {
    Display *(*XOpenDisplay)(const char *);
    XWindow (*XDefaultRootWindow)(Display *);
    Bool (*XQueryPointer)(Display *, XWindow, XWindow *, XWindow *, int *, int *, int *, int *, unsigned int *);
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
        std::fprintf(stderr, "ragekit: no X11 (XWayland) display; this window's title bar can't move it on Wayland\n");
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

#endif
