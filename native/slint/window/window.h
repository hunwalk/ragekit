// Window work Slint's C++ API doesn't offer, for frameless windows (screens with a layout): moving
// and resizing with the OS's own loop (so snapping works), and the floating look. One file per
// platform, each wrapped whole in #if: window_x11.cc, window_win32.cc. Used by the bridge (release
// builds) and the dev shell.
#pragma once

#include <slint.h>

namespace rk::win {

/** Resize directions, in _NET_WM_MOVERESIZE order. */
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

/** The primary button was just pressed on a drag area: let the OS move the window. */
void start_move(slint::Window &window);

/** The pointer moved with the button held: moves the window by hand where the OS can't (no window manager). */
void move(slint::Window &window);

/** The primary button was just pressed on an edge grip: let the OS resize the window. */
void start_resize(slint::Window &window, Edge edge);

} // namespace rk::win
