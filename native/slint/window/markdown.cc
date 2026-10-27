// rk::rich (markdown.h): chat text → Slint's StyledText.
#include "markdown.h"

#include <algorithm>
#include <cstring>
#include <utility>
#include <vector>

namespace rk::rich {

namespace {

// The common ones (GitHub/Slack names); anything else stays as typed. The same list is
// RageKit\Emoji::SHORTCODES (suggestions while typing): change both.
const std::pair<const char *, const char *> kShortcodes[] = {
    {"+1", "👍"}, {"-1", "👎"}, {"100", "💯"}, {"angry", "😠"}, {"astonished", "😲"}, {"awesome", "😎"},
    {"bang", "❗"}, {"beer", "🍺"}, {"beers", "🍻"}, {"blush", "😊"}, {"boom", "💥"}, {"broken_heart", "💔"},
    {"bug", "🐛"}, {"cake", "🎂"}, {"camera", "📷"}, {"cat", "🐱"}, {"check", "✔️"}, {"clap", "👏"},
    {"coffee", "☕"}, {"confused", "😕"}, {"cool", "🆒"}, {"cry", "😢"}, {"dancer", "💃"}, {"disappointed", "😞"},
    {"dog", "🐶"}, {"eyes", "👀"}, {"facepalm", "🤦"}, {"fire", "🔥"}, {"flushed", "😳"}, {"frowning", "😦"},
    {"ghost", "👻"}, {"gift", "🎁"}, {"grimacing", "😬"}, {"grin", "😁"}, {"grinning", "😀"}, {"hand", "✋"},
    {"heart", "❤️"}, {"heart_eyes", "😍"}, {"hearts", "♥️"}, {"heavy_check_mark", "✔️"}, {"hugs", "🤗"},
    {"hushed", "😯"}, {"innocent", "😇"}, {"joy", "😂"}, {"kiss", "😘"}, {"kissing_heart", "😘"},
    {"laughing", "😆"}, {"lol", "😂"}, {"memo", "📝"}, {"metal", "🤘"}, {"moneybag", "💰"}, {"muscle", "💪"},
    {"neutral_face", "😐"}, {"no_mouth", "😶"}, {"ok", "🆗"}, {"ok_hand", "👌"}, {"open_mouth", "😮"},
    {"partying_face", "🥳"}, {"pensive", "😔"}, {"point_down", "👇"}, {"point_left", "👈"},
    {"point_right", "👉"}, {"point_up", "☝️"}, {"poop", "💩"}, {"pray", "🙏"}, {"punch", "👊"},
    {"question", "❓"}, {"rage", "😡"}, {"raised_hands", "🙌"}, {"relaxed", "☺️"}, {"relieved", "😌"},
    {"rocket", "🚀"}, {"rofl", "🤣"}, {"rolling_eyes", "🙄"}, {"see_no_evil", "🙈"}, {"shrug", "🤷"},
    {"skull", "💀"}, {"sleeping", "😴"}, {"slightly_smiling_face", "🙂"}, {"smile", "😄"}, {"smiley", "😃"},
    {"smirk", "😏"}, {"sob", "😭"}, {"sparkles", "✨"}, {"star", "⭐"}, {"star_struck", "🤩"},
    {"stuck_out_tongue", "😛"}, {"stuck_out_tongue_winking_eye", "😜"}, {"sunglasses", "😎"},
    {"sweat", "😓"}, {"sweat_smile", "😅"}, {"tada", "🎉"}, {"thinking", "🤔"}, {"thumbsdown", "👎"},
    {"thumbsup", "👍"}, {"tired_face", "😫"}, {"trophy", "🏆"}, {"unamused", "😒"}, {"upside_down_face", "🙃"},
    {"v", "✌️"}, {"warning", "⚠️"}, {"wave", "👋"}, {"weary", "😩"}, {"white_check_mark", "✅"},
    {"wink", "😉"}, {"worried", "😟"}, {"x", "❌"}, {"yum", "😋"}, {"zany_face", "🤪"}, {"zap", "⚡"},
    {"zipper_mouth_face", "🤐"}, {"zzz", "💤"},
};

bool name_char(char c)
{
    return (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '_' || c == '+' || c == '-';
}

/** Escapes what would be read as markdown inside a link's label. */
std::string label(std::string_view text)
{
    std::string out;
    for (char c : text) {
        if (std::strchr("\\`*_[]<>~", c) != nullptr) {
            out += '\\';
        }
        out += c;
    }
    return out;
}

/** Where a bare URL starting at `at` ends: whitespace, minus trailing punctuation (and an unmatched ')'). */
size_t url_end(std::string_view line, size_t at)
{
    size_t end = at;
    while (end < line.size() && line[end] != ' ' && line[end] != '\t' && line[end] != '<' && line[end] != '>') {
        end++;
    }
    while (end > at) {
        char last = line[end - 1];
        if (std::strchr(".,;:!?'\"*_~", last) != nullptr) {
            end--;
        } else if (last == ')' && std::count(line.begin() + at, line.begin() + end, '(')
                                       < std::count(line.begin() + at, line.begin() + end, ')')) {
            end--;
        } else {
            break;
        }
    }
    return end;
}

/** One line outside code blocks: links for bare URLs, emoji for shortcodes, '<' kept literal. */
std::string inline_text(std::string_view line)
{
    std::string out;
    size_t ticks = 0; // the backtick run that opened the current code span (0: not in one)
    for (size_t i = 0; i < line.size();) {
        char c = line[i];
        if (c == '`') {
            size_t run = 0;
            while (i + run < line.size() && line[i + run] == '`') {
                run++;
            }
            ticks = ticks == 0 ? run : (ticks == run ? 0 : ticks);
            out.append(line.substr(i, run));
            i += run;
            continue;
        }
        if (ticks != 0) {
            out += c;
            i++;
            continue;
        }
        if (c == '\\' && i + 1 < line.size()) {
            out.append(line.substr(i, 2)); // an escape the writer meant
            i += 2;
            continue;
        }
        bool word_start = i == 0 || line[i - 1] == ' ' || line[i - 1] == '\t' || line[i - 1] == '(';
        bool in_link = i >= 2 && line[i - 1] == '(' && line[i - 2] == ']'; // [label](url): already a link
        if (word_start && !in_link && (line.substr(i, 8) == "https://" || line.substr(i, 7) == "http://")) {
            size_t end = url_end(line, i);
            std::string_view url = line.substr(i, end - i);
            out += "[" + label(url) + "](<" + std::string(url) + ">)";
            i = end;
            continue;
        }
        if (c == ':') {
            size_t end = i + 1;
            while (end < line.size() && name_char(line[end])) {
                end++;
            }
            if (end < line.size() && line[end] == ':' && end > i + 1) {
                std::string_view emoji = shortcode(line.substr(i + 1, end - i - 1));
                if (!emoji.empty()) {
                    out.append(emoji);
                    i = end + 1;
                    continue;
                }
            }
        }
        if (c == '<') {
            out += "\\<"; // no inline HTML: Slint's parser doesn't draw it
            i++;
            continue;
        }
        out += c;
        i++;
    }
    return out;
}

/** Block syntax Slint doesn't draw (or that a chat writer rarely means) stays as typed. */
std::string literal_blocks(std::string line)
{
    size_t indent = line.find_first_not_of(' ');
    if (indent == std::string::npos) {
        return "";
    }
    if (indent >= 4) {
        line.erase(0, indent); // not an indented code block
        indent = 0;
    }
    char first = line[indent];
    if (first == '#' || first == '>' || first == '=' || first == '|') {
        line.insert(indent, "\\");
        return line;
    }
    std::string rest = line.substr(indent);
    if (rest.find_first_not_of("-*_ ") == std::string::npos && std::count_if(rest.begin(), rest.end(), [](char c) { return c != ' '; }) >= 3) {
        line.insert(indent, "\\"); // a thematic break (---)
        return line;
    }
    size_t digits = indent;
    while (digits < line.size() && line[digits] >= '0' && line[digits] <= '9') {
        digits++;
    }
    if (digits > indent && digits + 1 < line.size() && (line[digits] == '.' || line[digits] == ')') && line[digits + 1] == ' ') {
        line.insert(digits, "\\"); // "1. " stays text: numbered lists aren't drawn
    }
    return line;
}

/** A line of a fenced code block as a code span (its indentation kept). */
std::string code_line(const std::string &line)
{
    if (line.find_first_not_of(' ') == std::string::npos) {
        return "\xC2\xA0"; // an empty line keeps its height
    }
    size_t longest = 0, run = 0;
    for (char c : line) {
        run = c == '`' ? run + 1 : 0;
        longest = std::max(longest, run);
    }
    std::string fence(longest + 1, '`');
    std::string body = line;
    size_t lead = body.find_first_not_of(' ');
    for (size_t i = 0; i < lead; i++) {
        body.replace(i * 2, 1, "\xC2\xA0"); // no-break spaces: a code span would trim plain ones
    }
    return fence + " " + body + " " + fence;
}

bool plain(std::string_view text)
{
    for (char c : text) {
        if (std::strchr("*_`~[]<>#:\\\n|=", c) != nullptr) {
            return false;
        }
    }
    return text.find("http") == std::string_view::npos;
}

} // namespace

std::string_view shortcode(std::string_view name)
{
    for (const auto &entry : kShortcodes) {
        if (name == entry.first) {
            return entry.second;
        }
    }
    return {};
}

std::string prepare(std::string_view text)
{
    std::vector<std::string> lines;
    std::string current;
    for (char c : text) {
        if (c == '\r') {
            continue;
        }
        if (c == '\n') {
            lines.push_back(std::move(current));
            current.clear();
        } else {
            current += c;
        }
    }
    lines.push_back(std::move(current));

    std::string out;
    bool fence = false;
    bool previous = false; // whether the last line written had text (a newline then is a line break)
    for (std::string &line : lines) {
        // Slint's format strings use U+E541 for their arguments: never in user text.
        for (size_t at; (at = line.find("\xEE\x95\x81")) != std::string::npos;) {
            line.replace(at, 3, "\xEF\xBF\xBD");
        }
        size_t indent = line.find_first_not_of(' ');
        if (indent != std::string::npos && line.compare(indent, 3, "```") == 0) {
            fence = !fence;
            continue;
        }
        std::string piece = fence ? code_line(line) : literal_blocks(line);
        if (!fence) {
            piece = inline_text(piece);
        }
        if (piece.empty()) {
            out += "\n";
            previous = false;
            continue;
        }
        if (previous) {
            out += "  \n"; // a single newline is a line break in a chat
        } else if (!out.empty()) {
            out += "\n";
        }
        out += piece;
        previous = true;
    }
    return out;
}

void click(slint::Window &window, float x, float y)
{
    slint::LogicalPosition at({x, y});
    window.dispatch_pointer_press_event(at, slint::PointerEventButton::Left);
    window.dispatch_pointer_release_event(at, slint::PointerEventButton::Left);
}

slint::StyledText markdown(std::string_view text)
{
    slint::SharedString shared(text);
    if (plain(text)) {
        return slint::private_api::string_to_styled_text(shared);
    }
    std::string prepared = prepare(text);
    return slint::private_api::parse_markdown(slint::SharedString(prepared), {});
}

} // namespace rk::rich
