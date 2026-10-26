// The Slint-free part of window.h, for the bridge's app-link code (rk_links.cc, which tests build
// without Slint): which display system to use, and the activation token a launch hands over.
#pragma once

#include <string>

namespace rk::win {

/**
 * Before Slint starts: run on X11 (XWayland) when both X11 and Wayland are there. A native Wayland
 * window can't be moved by the app itself (frameless windows), nor come to the front for a link
 * without an activation token, which browsers don't pass yet (Firefox 153). RAGEKIT_WAYLAND=1 or an
 * explicit SLINT_BACKEND keeps Wayland.
 */
void prefer_x11();

/**
 * The activation token of the launch that handed over (its XDG_ACTIVATION_TOKEN on Wayland or
 * DESKTOP_STARTUP_ID on X11), for the next raise(). Any thread; Windows: ignored.
 */
void set_activation_token(const std::string &token);

/** The token set_activation_token() left, once ('' if none). */
std::string take_activation_token();

} // namespace rk::win
