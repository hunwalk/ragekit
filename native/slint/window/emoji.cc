// rk::emoji (emoji.h): splitting text into emoji sequences, and the cache in front of draw().
#include "emoji.h"

#include <algorithm>
#include <cmath>
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

/**
 * The image scaled down to fit `pixels` × `pixels`, each new pixel the average of the source pixels
 * it covers (with their fractions at the edges), in premultiplied alpha so transparent pixels
 * don't darken the edges. Smaller images, and sizes it can't read, come back as they are.
 */
slint::Image shrink(const slint::Image &image, int pixels)
{
    auto size = image.size();
    if (pixels <= 0 || size.width == 0 || size.height == 0 || (size.width <= static_cast<uint32_t>(pixels) && size.height <= static_cast<uint32_t>(pixels))) {
        return image;
    }
    auto source = image.to_rgba8_premultiplied();
    if (!source) {
        return image;
    }
    double scale = static_cast<double>(pixels) / std::max(size.width, size.height);
    uint32_t width = std::max<uint32_t>(1, static_cast<uint32_t>(std::lround(size.width * scale)));
    uint32_t height = std::max<uint32_t>(1, static_cast<uint32_t>(std::lround(size.height * scale)));
    double stepX = static_cast<double>(size.width) / width, stepY = static_cast<double>(size.height) / height;
    const slint::Rgba8Pixel *in = source->begin();
    slint::SharedPixelBuffer<slint::Rgba8Pixel> result(width, height);
    slint::Rgba8Pixel *out = result.begin();
    for (uint32_t y = 0; y < height; y++) {
        double top = y * stepY, bottom = top + stepY;
        for (uint32_t x = 0; x < width; x++) {
            double left = x * stepX, right = left + stepX;
            double sum[4] = {0, 0, 0, 0}, area = 0;
            for (uint32_t sy = static_cast<uint32_t>(top); sy < size.height && sy < bottom; sy++) {
                double h = std::min<double>(sy + 1, bottom) - std::max<double>(sy, top);
                for (uint32_t sx = static_cast<uint32_t>(left); sx < size.width && sx < right; sx++) {
                    double w = (std::min<double>(sx + 1, right) - std::max<double>(sx, left)) * h;
                    const slint::Rgba8Pixel &p = in[static_cast<size_t>(sy) * size.width + sx];
                    sum[0] += p.r * w;
                    sum[1] += p.g * w;
                    sum[2] += p.b * w;
                    sum[3] += p.a * w;
                    area += w;
                }
            }
            double a = sum[3] / area;
            auto straight = [a, area](double v) {
                return static_cast<uint8_t>(a <= 0 ? 0 : std::clamp(std::lround(v / area * 255.0 / a), 0L, 255L));
            };
            out[static_cast<size_t>(y) * width + x] = slint::Rgba8Pixel{straight(sum[0]), straight(sum[1]), straight(sum[2]), static_cast<uint8_t>(std::lround(a))};
        }
    }
    return slint::Image(result);
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

std::vector<slint::Image> images(std::string_view text, int pixels)
{
    static std::map<std::string, slint::Image, std::less<>> drawn;
    static std::map<std::pair<std::string, int>, slint::Image> sized;
    std::vector<slint::Image> out;
    for (const std::string &sequence : sequences(text)) {
        auto it = sized.find({sequence, pixels});
        if (it == sized.end()) {
            auto master = drawn.find(sequence);
            if (master == drawn.end()) {
                master = drawn.emplace(sequence, draw(sequence)).first;
            }
            it = sized.emplace(std::make_pair(sequence, pixels), shrink(master->second, pixels)).first;
        }
        if (it->second.size().width > 0) {
            out.push_back(it->second);
        }
    }
    return out;
}

} // namespace rk::emoji
