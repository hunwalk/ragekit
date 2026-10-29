// Window work Slint's C++ API doesn't offer, for frameless windows (screens with a layout): moving
// and resizing (Windows: the OS's own loop, with snapping; X11: by the app, following the pointer),
// and the floating look. One file per
// platform, each wrapped whole in #if: window_x11.cc, window_win32.cc. Used by the bridge (release
// builds) and the dev shell.
#pragma once

#include "activation.h"

#include <slint.h>

namespace rk::win {

/** Resize directions: corners and edges, clockwise from the top-left (the grips' order). */
enum class Edge { TopLeft = 0, Top, TopRight, Right, BottomRight, Bottom, BottomLeft, Left };

/** After the window is shown: the OS's shadow and rounded corners (Windows 11; elsewhere a no-op). */
void make_floating(slint::Window &window);

/**
 * After the window is shown (Windows; elsewhere a no-op): no frames are drawn while it's minimised,
 * and the first frame after a restore or a show draws the whole window. Slint's software renderer
 * copies only the changed parts to the window, which left a restored window black elsewhere.
 */
void skip_frames_while_minimised(slint::Window &window);

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
 * Brings the window to the front (another launch of a single-instance app). Wayland: xdg-activation
 * with the token set_activation_token() got (else one of its own); X11: asks the window manager
 * (_NET_ACTIVE_WINDOW, with the handed-over startup id's time) for this process's top-level window
 * with this title; Windows: SetForegroundWindow. A desktop may still only mark it as wanting attention.
 */
void raise(slint::Window &window, const char *title);

/** Wayland: activates the window (see raise()); false when it isn't a Wayland window. */
bool activate_wayland(slint::Window &window);

} // namespace rk::win
