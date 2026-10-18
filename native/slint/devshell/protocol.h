// The dev shell's message format, shared with src/Slint/Dev/Protocol.php: one message
// per line, fields separated by tabs, with backslash, tab, newline and CR escaped.
#pragma once

#include <string>
#include <string_view>
#include <vector>

namespace rk::dev {

std::string encode(const std::vector<std::string> &fields); // without the trailing newline
std::vector<std::string> decode(std::string_view line);    // a trailing newline is ignored

} // namespace rk::dev
