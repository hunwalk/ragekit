// Chat-style formatted text for <Text markdown="true">: a message's text → Slint's StyledText
// (bold, italic, code, strikethrough, links, lists), through Slint's own markdown parser after
// preparing what it doesn't take or a chat writer means otherwise: fenced code blocks become code
// lines, bare URLs links, single newlines line breaks, :shortcodes: emoji, and block syntax Slint
// can't draw (headings, quotes, rules, numbered lists, HTML) stays literal text. Used by the bridge
// (release builds) and the dev shell.
#pragma once

#include <slint.h>

#include <string>
#include <string_view>

namespace rk::rich {

/** The text as markdown Slint's parser takes without complaint (see above). */
std::string prepare(std::string_view text);

/** The text, prepared and parsed (plain text needs no parsing and isn't). */
slint::StyledText markdown(std::string_view text);

/**
 * A left click (press and release) at this window position. RkRich.click calls it on the next turn
 * of the event loop: markdown text under a context menu sends clicks again to the text underneath,
 * for its links.
 */
void click(slint::Window &window, float x, float y);

/** ":thumbsup:" → "👍" (without colons); '' when the name isn't known. */
std::string_view shortcode(std::string_view name);

} // namespace rk::rich
