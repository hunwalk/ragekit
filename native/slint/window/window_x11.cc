// rk::win on Linux/X11: _NET_WM_MOVERESIZE asks the window manager to move or resize the window
// (with its snapping). libX11 is loaded at runtime, so apps don't depend on it; without it, or
// without a window manager that supports the message (e.g. Xvfb), the window is moved by hand.
#if !defined(_WIN32) && !defined(__APPLE__)

#include "window.h"
#include "double_click.h"

#include <chrono>

#include <dlfcn.h>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <type_traits>

namespace {

// The few Xlib types and functions used, declared here instead of including X11 headers (not
// needed to build, and libX11 is optional at runtime).
using Display = struct _XDisplay;
using XWindow = unsigned long;
using Atom = unsigned long;
using Bool = int;
using Status = int;

struct XClientMessageEvent {
    int type;
    unsigned long serial;
    Bool send_event;
    Display *display;
    XWindow window;
    Atom message_type;
    int format;
    long l[5];
};

union XEvent {
    int type;
    XClientMessageEvent xclient;
    long pad[24];
};

constexpr int ClientMessage = 33;
constexpr long SubstructureNotifyMask = 1L << 19;
constexpr long SubstructureRedirectMask = 1L << 20;
constexpr Atom XA_ATOM = 4;
constexpr int MoveResizeMove = 8;

struct Xlib {
    Display *(*XOpenDisplay)(const char *);
    XWindow (*XDefaultRootWindow)(Display *);
    Bool (*XQueryPointer)(Display *, XWindow, XWindow *, XWindow *, int *, int *, int *, int *, unsigned int *);
    Atom (*XInternAtom)(Display *, const char *, Bool);
    int (*XGetWindowProperty)(Display *, XWindow, Atom, long, long, Bool, Atom, Atom *, int *, unsigned long *, unsigned long *, unsigned char **);
    int (*XFree)(void *);
    Status (*XSendEvent)(Display *, XWindow, Bool, long, XEvent *);
    int (*XUngrabPointer)(Display *, unsigned long);
    int (*XFlush)(Display *);
    Display *display = nullptr;
};

// Opened once, on first use; nullptr when libX11 or the X display isn't there, or when the app
// runs on native Wayland (prefer_x11() unsets WAYLAND_DISPLAY whenever it switches to X11): our
// windows aren't X11 windows then, and the pointer XWayland reports may be over another app's.
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
        || !load(lib.XQueryPointer, "XQueryPointer") || !load(lib.XInternAtom, "XInternAtom")
        || !load(lib.XGetWindowProperty, "XGetWindowProperty") || !load(lib.XFree, "XFree")
        || !load(lib.XSendEvent, "XSendEvent") || !load(lib.XUngrabPointer, "XUngrabPointer")
        || !load(lib.XFlush, "XFlush")) {
        return nullptr;
    }
    lib.display = lib.XOpenDisplay(nullptr);
    return lib.display != nullptr ? &lib : nullptr;
}

bool hasProperty(Xlib &x, XWindow window, Atom property)
{
    Atom type = 0;
    int format = 0;
    unsigned long count = 0, after = 0;
    unsigned char *data = nullptr;
    int status = x.XGetWindowProperty(x.display, window, property, 0, 0, 0, 0 /* AnyPropertyType */, &type, &format, &count, &after, &data);
    if (data != nullptr) {
        x.XFree(data);
    }
    return status == 0 && type != 0;
}

/** Whether the window manager handles _NET_WM_MOVERESIZE (it lists it in _NET_SUPPORTED). */
bool wmMovesWindows(Xlib &x)
{
    Atom supported = x.XInternAtom(x.display, "_NET_SUPPORTED", 0);
    Atom moveResize = x.XInternAtom(x.display, "_NET_WM_MOVERESIZE", 0);
    Atom type = 0;
    int format = 0;
    unsigned long count = 0, after = 0;
    unsigned char *data = nullptr;
    bool found = false;
    if (x.XGetWindowProperty(x.display, x.XDefaultRootWindow(x.display), supported, 0, 4096, 0, XA_ATOM, &type, &format, &count, &after, &data) == 0 && data != nullptr) {
        auto *atoms = reinterpret_cast<unsigned long *>(data);
        for (unsigned long i = 0; i < count && !found; ++i) {
            found = atoms[i] == moveResize;
        }
    }
    if (data != nullptr) {
        x.XFree(data);
    }
    return found;
}

/**
 * The client window under the pointer (ours: the button was just pressed on it) and the pointer's
 * root position. Walks down from the root to the window with WM_STATE; without a window manager
 * (no WM_STATE anywhere), the top-level window.
 */
XWindow windowUnderPointer(Xlib &x, int &rootX, int &rootY)
{
    XWindow root = x.XDefaultRootWindow(x.display);
    Atom wmState = x.XInternAtom(x.display, "WM_STATE", 0);
    XWindow current = root, topLevel = 0;
    for (int depth = 0; depth < 16; ++depth) {
        XWindow rootReturn = 0, child = 0;
        int winX = 0, winY = 0;
        unsigned int mask = 0;
        if (!x.XQueryPointer(x.display, current, &rootReturn, &child, &rootX, &rootY, &winX, &winY, &mask) || child == 0) {
            break;
        }
        if (topLevel == 0) {
            topLevel = child;
        }
        if (hasProperty(x, child, wmState)) {
            return child;
        }
        current = child;
    }
    return topLevel;
}

// The hand-made move: where the pointer and the window were when the button went down.
struct Drag {
    bool active = false;
    int pointerX = 0, pointerY = 0;
    int windowX = 0, windowY = 0;
} drag;

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

/** Asks the window manager to run its move (direction 8) or resize (0-7) loop; false if it can't. */
bool moveResize(int direction)
{
    Xlib *x = xlib();
    if (x == nullptr || !wmMovesWindows(*x)) {
        return false;
    }
    int rootX = 0, rootY = 0;
    XWindow window = windowUnderPointer(*x, rootX, rootY);
    if (window == 0) {
        return false;
    }
    // Releases a grab this connection might hold; the press's grab is winit's (see start_move).
    x->XUngrabPointer(x->display, 0 /* CurrentTime */);
    XEvent event;
    std::memset(&event, 0, sizeof event);
    event.xclient.type = ClientMessage;
    event.xclient.window = window;
    event.xclient.message_type = x->XInternAtom(x->display, "_NET_WM_MOVERESIZE", 0);
    event.xclient.format = 32;
    event.xclient.l[0] = rootX;
    event.xclient.l[1] = rootY;
    event.xclient.l[2] = direction;
    event.xclient.l[3] = 1; // Button1
    event.xclient.l[4] = 1; // a normal application
    x->XSendEvent(x->display, x->XDefaultRootWindow(x->display), 0, SubstructureRedirectMask | SubstructureNotifyMask, &event);
    x->XFlush(x->display);
    return true;
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
    drag.active = false;
    // The window manager may not get the pointer: the press grabbed it on winit's connection, which
    // only winit can release. Stay ready to move by hand: while the WM moves the window, the app
    // gets no pointer motion, so move() only runs when the WM didn't take the drag.
    moveResize(MoveResizeMove);
    int x = 0, y = 0;
    if (pointer(x, y)) {
        auto position = window.position();
        drag = Drag{true, x, y, position.x, position.y};
    }
}

void move(slint::Window &window)
{
    int x = 0, y = 0;
    if (drag.active && pointer(x, y)) {
        window.set_position(slint::PhysicalPosition({drag.windowX + x - drag.pointerX, drag.windowY + y - drag.pointerY}));
    }
}

void start_resize(slint::Window &, Edge edge)
{
    drag.active = false;
    moveResize(static_cast<int>(edge)); // without a window manager there's no resizing
}

} // namespace rk::win

#endif
