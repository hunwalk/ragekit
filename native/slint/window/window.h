// Window work Slint's C++ API doesn't offer, for frameless windows (screens with a layout): moving
// and resizing (Windows: the OS's own loop, with snapping; X11: by the app, following the pointer),
// and the floating look. One file per
// platform, each wrapped whole in #if: window_x11.cc, window_win32.cc. Used by the bridge (release
// builds) and the dev shell.
#pragma once

#include <slint.h>

namespace rk::win {

/** Resize directions: corners and edges, clockwise from the top-left (the grips' order). */
enum class Edge { TopLeft = 0, Top, TopRight, Right, BottomRight, Bottom, BottomLeft, Left };

/**
 * Before Slint starts: run on X11 (XWayland) when both X11 and Wayland are there. A native Wayland
 * window can't be moved by the app itself. RAGEKIT_WAYLAND=1 or an explicit SLINT_BACKEND keeps
 * Wayland.
 */
void prefer_x11();

/** After the window is shown: the OS's shadow and rounded corners (Windows 11; elsewhere a no-op). */
void make_floating(slint::Window &window);

/**
 * The primary button was just pressed on a drag area: whether this press completes a double-click
 * (then maximise instead of moving). Uses the OS's double-click time and distance.
 */
bool double_click();

/** The primary button was just pressed on a drag area: start moving the window. */
void start_move(slint::Window &window);

/** The pointer moved with the button held on a drag area (X11: the app moves the window). */
void move(slint::Window &window);

/** The primary button was just pressed on an edge grip: start resizing the window. */
void start_resize(slint::Window &window, Edge edge);

/** The pointer moved with the button held on an edge grip (X11: the app resizes the window). */
void resize(slint::Window &window);

/**
 * Brings the window to the front (another launch of a single-instance app). X11: asks the window
 * manager (_NET_ACTIVE_WINDOW) for this process's top-level window with this title; Windows:
 * SetForegroundWindow. A window manager may still only mark it as wanting attention.
 */
void raise(slint::Window &window, const char *title);

} // namespace rk::win
