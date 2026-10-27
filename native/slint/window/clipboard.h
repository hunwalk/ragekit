// Images on the clipboard, which Slint's clipboard (text only) doesn't offer: a screenshot, an image
// copied in a browser or image editor, or an image file copied in a file manager. One file per
// platform, each wrapped whole in #if: clipboard_x11.cc (X11 selections through libX11 loaded at
// runtime, or wl-paste on a Wayland-only session), clipboard_win32.cc. Used by the bridge (release
// builds) and the dev shell.
#pragma once

#include <string>

namespace rk::clip {

/** Whether the clipboard holds an image (asks the clipboard's owner; quick, gives up after 0.5 s). */
bool has_image();

/**
 * The clipboard's image as a file's bytes, and its extension ("png", "jpg", …; Windows bitmaps
 * become png). False when there's none.
 */
bool image(std::string &bytes, std::string &extension);

/**
 * Writes the clipboard's image into `folder` (created if missing) as `pasted-<hash>.<ext>`, named by
 * its content (the same image pasted twice is one file). Its path, or '' when there's none.
 */
std::string save_image(const std::string &folder);

} // namespace rk::clip
