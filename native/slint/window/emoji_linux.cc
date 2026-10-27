// rk::emoji::draw on Linux: the colour emoji font fontconfig names for "emoji" (Noto Color Emoji on
// most desktops), HarfBuzz to turn a sequence into the font's glyph (ZWJ sequences, flags and skin
// tones are ligatures there), and the PNG the font stores for that glyph (CBDT/sbix, as in Noto
// Color Emoji), decoded by Slint. Both libraries are loaded at runtime. A font with vector colour
// glyphs only (COLR, e.g. Fedora's Noto-COLRv1) gives no images: the text then shows the emoji the
// usual way.
#if !defined(_WIN32) && !defined(__APPLE__)

#include "emoji.h"

#include <dlfcn.h>
#include <sys/stat.h>

#include <cstdint>
#include <span>
#include <string>

namespace {

using Ptr = void *;

struct GlyphInfo { // hb_glyph_info_t
    uint32_t codepoint;
    uint32_t mask;
    uint32_t cluster;
    uint32_t var1;
    uint32_t var2;
};

struct HarfBuzz {
    bool ok = false;
    Ptr (*blobFromFile)(const char *) = nullptr;
    Ptr (*faceCreate)(Ptr, unsigned) = nullptr;
    Ptr (*fontCreate)(Ptr) = nullptr;
    int (*hasPng)(Ptr) = nullptr;
    Ptr (*bufferCreate)() = nullptr;
    void (*bufferAdd)(Ptr, const char *, int, unsigned, int) = nullptr;
    void (*guess)(Ptr) = nullptr;
    void (*shape)(Ptr, Ptr, const void *, unsigned) = nullptr;
    GlyphInfo *(*infos)(Ptr, unsigned *) = nullptr;
    void (*bufferDestroy)(Ptr) = nullptr;
    Ptr (*png)(Ptr, unsigned) = nullptr;
    const char *(*blobData)(Ptr, unsigned *) = nullptr;
    void (*blobDestroy)(Ptr) = nullptr;
};

template <typename T>
bool find(void *handle, const char *name, T &out)
{
    out = reinterpret_cast<T>(dlsym(handle, name));
    return out != nullptr;
}

HarfBuzz &hb()
{
    static HarfBuzz h = [] {
        HarfBuzz l;
        void *lib = dlopen("libharfbuzz.so.0", RTLD_NOW | RTLD_LOCAL);
        if (lib == nullptr) {
            return l;
        }
        l.ok = find(lib, "hb_blob_create_from_file", l.blobFromFile) && find(lib, "hb_face_create", l.faceCreate)
            && find(lib, "hb_font_create", l.fontCreate) && find(lib, "hb_ot_color_has_png", l.hasPng)
            && find(lib, "hb_buffer_create", l.bufferCreate) && find(lib, "hb_buffer_add_utf8", l.bufferAdd)
            && find(lib, "hb_buffer_guess_segment_properties", l.guess) && find(lib, "hb_shape", l.shape)
            && find(lib, "hb_buffer_get_glyph_infos", l.infos) && find(lib, "hb_buffer_destroy", l.bufferDestroy)
            && find(lib, "hb_ot_color_glyph_reference_png", l.png) && find(lib, "hb_blob_get_data", l.blobData)
            && find(lib, "hb_blob_destroy", l.blobDestroy);
        return l;
    }();
    return h;
}

/** The emoji font's file: fontconfig's match for "emoji", else the usual places. */
std::string font_file()
{
    std::string found;
    if (void *fc = dlopen("libfontconfig.so.1", RTLD_NOW | RTLD_LOCAL)) {
        Ptr (*init)() = nullptr;
        Ptr (*parse)(const unsigned char *) = nullptr;
        int (*substitute)(Ptr, Ptr, int) = nullptr;
        void (*defaults)(Ptr) = nullptr;
        Ptr (*match)(Ptr, Ptr, int *) = nullptr;
        int (*getString)(Ptr, const char *, int, unsigned char **) = nullptr;
        void (*destroy)(Ptr) = nullptr;
        if (find(fc, "FcInitLoadConfigAndFonts", init) && find(fc, "FcNameParse", parse) && find(fc, "FcConfigSubstitute", substitute)
            && find(fc, "FcDefaultSubstitute", defaults) && find(fc, "FcFontMatch", match) && find(fc, "FcPatternGetString", getString)
            && find(fc, "FcPatternDestroy", destroy)) {
            Ptr config = init();
            Ptr pattern = parse(reinterpret_cast<const unsigned char *>("emoji:color=true"));
            if (config != nullptr && pattern != nullptr) {
                substitute(config, pattern, 0 /* FcMatchPattern */);
                defaults(pattern);
                int result = 0;
                if (Ptr best = match(config, pattern, &result)) {
                    unsigned char *file = nullptr;
                    if (getString(best, "file", 0, &file) == 0 /* FcResultMatch */ && file != nullptr) {
                        found = reinterpret_cast<char *>(file);
                    }
                    destroy(best);
                }
            }
            if (pattern != nullptr) {
                destroy(pattern);
            }
        }
    }
    struct stat st{};
    if (!found.empty() && stat(found.c_str(), &st) == 0) {
        return found;
    }
    for (const char *path : {"/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf", "/usr/share/fonts/noto/NotoColorEmoji.ttf",
                             "/usr/share/fonts/google-noto-color-emoji-fonts/NotoColorEmoji.ttf",
                             "/usr/share/fonts/noto-emoji/NotoColorEmoji.ttf"}) {
        if (stat(path, &st) == 0) {
            return path;
        }
    }
    return "";
}

/** The font, once: null when there's no HarfBuzz or no colour emoji font with PNG glyphs. */
Ptr font()
{
    static Ptr f = []() -> Ptr {
        HarfBuzz &h = hb();
        if (!h.ok) {
            return nullptr;
        }
        std::string file = font_file();
        if (file.empty()) {
            return nullptr;
        }
        Ptr face = h.faceCreate(h.blobFromFile(file.c_str()), 0);
        if (face == nullptr || h.hasPng(face) == 0) {
            return nullptr;
        }
        return h.fontCreate(face);
    }();
    return f;
}

} // namespace

namespace rk::emoji {

slint::Image draw(const std::string &sequence)
{
    Ptr f = font();
    if (f == nullptr) {
        return {};
    }
    HarfBuzz &h = hb();
    Ptr buffer = h.bufferCreate();
    h.bufferAdd(buffer, sequence.data(), static_cast<int>(sequence.size()), 0, static_cast<int>(sequence.size()));
    h.guess(buffer);
    h.shape(f, buffer, nullptr, 0);
    unsigned count = 0;
    GlyphInfo *glyphs = h.infos(buffer, &count);
    slint::Image image;
    // A sequence the font has a ligature for is one glyph; one it doesn't know (a newer ZWJ
    // sequence) falls apart into its parts: its first emoji stands for it.
    for (unsigned i = 0; i < count && image.size().width == 0; i++) {
        if (glyphs[i].codepoint == 0) {
            continue; // .notdef
        }
        Ptr blob = h.png(f, glyphs[i].codepoint);
        unsigned length = 0;
        const char *data = h.blobData(blob, &length);
        if (data != nullptr && length > 0) {
            image = slint::Image::load_from_data(std::span<const uint8_t>(reinterpret_cast<const uint8_t *>(data), length), "png");
        }
        h.blobDestroy(blob);
    }
    h.bufferDestroy(buffer);
    return image;
}

} // namespace rk::emoji

#endif
