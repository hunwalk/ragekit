// rk::emoji (emoji.h): splitting text into emoji sequences, and the cache in front of draw().
#include "emoji.h"

#include <map>

namespace rk::emoji {

namespace {

/** Decodes the code point at `i` (advancing it); U+FFFD for a broken sequence. */
char32_t next(std::string_view text, size_t &i)
{
    unsigned char c = static_cast<unsigned char>(text[i]);
    int extra = c < 0x80 ? 0 : (c >> 5) == 0x6 ? 1 : (c >> 4) == 0xE ? 2 : (c >> 3) == 0x1E ? 3 : -1;
    if (extra < 0 || i + static_cast<size_t>(extra) >= text.size() + (extra == 0 ? 1 : 0)) {
        i++;
        return 0xFFFD;
    }
    char32_t cp = extra == 0 ? c : extra == 1 ? (c & 0x1F) : extra == 2 ? (c & 0x0F) : (c & 0x07);
    for (int k = 1; k <= extra; k++) {
        cp = (cp << 6) | (static_cast<unsigned char>(text[i + static_cast<size_t>(k)]) & 0x3F);
    }
    i += static_cast<size_t>(extra) + 1;
    return cp;
}

bool regional(char32_t cp)
{
    return cp >= 0x1F1E6 && cp <= 0x1F1FF;
}

/** Shown as emoji by default (an approximation of Unicode's Emoji_Presentation). */
bool pictographic(char32_t cp)
{
    if (cp >= 0x1F000 && cp <= 0x1FAFF && !(cp >= 0x1F1E6 && cp <= 0x1F1FF) && !(cp >= 0x1F3FB && cp <= 0x1F3FF)) {
        return true;
    }
    static const char32_t kDefault[] = {0x231A, 0x231B, 0x23E9, 0x23EA, 0x23EB, 0x23EC, 0x23F0, 0x23F3, 0x25FD, 0x25FE,
                                        0x2614, 0x2615, 0x267F, 0x2693, 0x26A1, 0x26AA, 0x26AB, 0x26BD, 0x26BE, 0x26C4,
                                        0x26C5, 0x26CE, 0x26D4, 0x26EA, 0x26F2, 0x26F3, 0x26F5, 0x26FA, 0x26FD, 0x2705,
                                        0x270A, 0x270B, 0x2728, 0x274C, 0x274E, 0x2753, 0x2754, 0x2755, 0x2757, 0x2795,
                                        0x2796, 0x2797, 0x27B0, 0x27BF, 0x2B1B, 0x2B1C, 0x2B50, 0x2B55};
    for (char32_t d : kDefault) {
        if (cp == d) {
            return true;
        }
    }
    return cp >= 0x2648 && cp <= 0x2653;
}

/** Shown as emoji only when U+FE0F follows (❤️, ☺️, ✔️, ©️, …). */
bool text_default(char32_t cp)
{
    return cp == 0x00A9 || cp == 0x00AE || cp == 0x203C || cp == 0x2049 || cp == 0x2122 || cp == 0x2139
        || (cp >= 0x2194 && cp <= 0x21AA) || (cp >= 0x2300 && cp <= 0x23FF) || cp == 0x24C2
        || (cp >= 0x25AA && cp <= 0x25FE) || (cp >= 0x2600 && cp <= 0x27BF) || cp == 0x2934 || cp == 0x2935
        || (cp >= 0x2B05 && cp <= 0x2B55) || cp == 0x3030 || cp == 0x303D || cp == 0x3297 || cp == 0x3299;
}

bool keycap_base(char32_t cp)
{
    return (cp >= '0' && cp <= '9') || cp == '#' || cp == '*';
}

/** What may follow inside a sequence: variation selector, skin tone, keycap, tags. */
bool modifier(char32_t cp)
{
    return cp == 0xFE0F || cp == 0xFE0E || (cp >= 0x1F3FB && cp <= 0x1F3FF) || cp == 0x20E3 || (cp >= 0xE0020 && cp <= 0xE007F);
}

char32_t peek(std::string_view text, size_t i)
{
    return i < text.size() ? next(text, i) : 0;
}

} // namespace

std::vector<std::string> sequences(std::string_view text)
{
    std::vector<std::string> out;
    size_t i = 0;
    while (i < text.size()) {
        size_t start = i;
        char32_t cp = next(text, i);
        char32_t after = peek(text, i);
        bool emoji = pictographic(cp) || (text_default(cp) && after == 0xFE0F)
            || (keycap_base(cp) && (after == 0x20E3 || after == 0xFE0F)) || (cp >= 0x1F3FB && cp <= 0x1F3FF);
        if (regional(cp) && regional(after)) {
            next(text, i); // a flag: two regional indicators
            out.emplace_back(text.substr(start, i - start));
            continue;
        }
        if (!emoji) {
            continue;
        }
        while (i < text.size()) {
            size_t at = i;
            char32_t more = next(text, at);
            if (modifier(more)) {
                i = at;
            } else if (more == 0x200D && at < text.size()) {
                next(text, at); // a zero width joiner and the emoji it joins
                i = at;
            } else {
                break;
            }
        }
        out.emplace_back(text.substr(start, i - start));
    }
    return out;
}

std::vector<slint::Image> images(std::string_view text)
{
    static std::map<std::string, slint::Image, std::less<>> cache;
    std::vector<slint::Image> out;
    for (const std::string &sequence : sequences(text)) {
        auto it = cache.find(sequence);
        if (it == cache.end()) {
            it = cache.emplace(sequence, draw(sequence)).first;
        }
        if (it->second.size().width > 0) {
            out.push_back(it->second);
        }
    }
    return out;
}

} // namespace rk::emoji
