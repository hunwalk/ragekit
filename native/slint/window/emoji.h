// Colour emoji as images (<Emoji>): Slint's software renderer draws glyph outlines in one colour
// and can't draw colour fonts, so the system's colour emoji font is drawn here instead. One file per
// platform, each wrapped whole in #if: emoji_linux.cc (HarfBuzz, loaded at runtime, shapes the
// sequence; the font's own PNG for the glyph, e.g. Noto Color Emoji), emoji_win32.cc (DirectWrite +
// Direct2D, Segoe UI Emoji). Segmentation is shared (emoji.cc). Used by the bridge (release builds)
// and the dev shell.
#pragma once

#include <slint.h>

#include <string>
#include <string_view>
#include <vector>

namespace rk::emoji {

/**
 * The emoji in the text in order, one per sequence (👍🏽, 👩‍💻, 🇭🇺 are one each), split into
 * sequences of UTF-8. Characters that aren't emoji are left out.
 */
std::vector<std::string> sequences(std::string_view text);

/**
 * One image per emoji in the text (see sequences()), each `pixels` wide and high (physical pixels:
 * the size it's shown at times the window's scale factor); one the font can't draw is left out.
 * The font's picture is bigger and is averaged down here: Slint's own scaling samples only the
 * nearest four pixels, which made emoji shown at a fraction of that size jagged. Cached.
 */
std::vector<slint::Image> images(std::string_view text, int pixels);

/** Platform part: the sequence drawn by the colour emoji font (an empty Image if it can't be). */
slint::Image draw(const std::string &sequence);

} // namespace rk::emoji
