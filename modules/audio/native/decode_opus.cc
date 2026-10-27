// rk::audio::decode on Linux (decode.h): an MP4 file's Opus track, from its sample tables (stsz,
// stsc, stco/co64) and its dOps box, decoded with libopus (libopus.so.0, which desktops ship;
// loaded at runtime, so apps don't depend on it). The decoder's pre-skip is dropped.
#ifndef _WIN32

#include "decode.h"

#include <dlfcn.h>

#include <algorithm>
#include <cstdint>
#include <cstring>
#include <fstream>
#include <initializer_list>
#include <iterator>

namespace rk::audio {

namespace {

uint32_t be32(const std::string &d, size_t at)
{
    return (uint32_t(uint8_t(d[at])) << 24) | (uint32_t(uint8_t(d[at + 1])) << 16) | (uint32_t(uint8_t(d[at + 2])) << 8) | uint8_t(d[at + 3]);
}

uint64_t be64(const std::string &d, size_t at)
{
    return (uint64_t(be32(d, at)) << 32) | be32(d, at + 4);
}

/** The child box of this type in [start, end): its data's start and end; false when there's none. */
bool child(const std::string &d, size_t start, size_t end, const char *type, size_t &from, size_t &to)
{
    for (size_t at = start; at + 8 <= end;) {
        uint64_t size = be32(d, at);
        size_t header = 8;
        if (size == 1 && at + 16 <= end) {
            size = be64(d, at + 8);
            header = 16;
        } else if (size == 0) {
            size = end - at;
        }
        if (size < header || at + size > end) {
            return false;
        }
        if (std::memcmp(d.data() + at + 4, type, 4) == 0) {
            from = at + header;
            to = at + size;
            return true;
        }
        at += size;
    }
    return false;
}

/** Follows a path of boxes (e.g. moov/trak/mdia): the last one's data. */
bool path(const std::string &d, std::initializer_list<const char *> types, size_t &from, size_t &to)
{
    size_t start = 0, end = d.size();
    for (const char *type : types) {
        if (!child(d, start, end, type, from, to)) {
            return false;
        }
        start = from;
        end = to;
    }
    return true;
}

struct Opus {
    bool ok = false;
    void *(*create)(int32_t rate, int channels, int *error) = nullptr;
    int (*decode)(void *decoder, const unsigned char *data, int32_t length, float *pcm, int frames, int fec) = nullptr;
    void (*destroy)(void *decoder) = nullptr;
};

Opus &opus()
{
    static Opus o = [] {
        Opus l;
        void *lib = dlopen("libopus.so.0", RTLD_NOW | RTLD_LOCAL);
        if (lib != nullptr) {
            l.create = reinterpret_cast<decltype(l.create)>(dlsym(lib, "opus_decoder_create"));
            l.decode = reinterpret_cast<decltype(l.decode)>(dlsym(lib, "opus_decode_float"));
            l.destroy = reinterpret_cast<decltype(l.destroy)>(dlsym(lib, "opus_decoder_destroy"));
            l.ok = l.create != nullptr && l.decode != nullptr && l.destroy != nullptr;
        }
        return l;
    }();
    return o;
}

} // namespace

bool decode(const std::string &file, Decoded &out, std::string &why)
{
    std::ifstream in(file, std::ios::binary);
    std::string d((std::istreambuf_iterator<char>(in)), std::istreambuf_iterator<char>());
    if (d.size() < 12 || d.compare(4, 4, "ftyp") != 0) {
        why = "not a file this app can play (WAV, MP3, FLAC, or Opus in MP4)";
        return false;
    }
    // The first track whose sample description is Opus.
    size_t moovFrom = 0, moovTo = 0;
    if (!path(d, {"moov"}, moovFrom, moovTo)) {
        why = "an MP4 file without a movie box";
        return false;
    }
    size_t stbl = 0, stblEnd = 0;
    bool found = false;
    for (size_t at = moovFrom; at + 8 <= moovTo && !found;) {
        size_t from = 0, to = 0;
        if (!child(d, at, moovTo, "trak", from, to)) {
            break;
        }
        std::string trak = d.substr(from, to - from);
        size_t a = 0, b = 0;
        if (path(trak, {"mdia", "minf", "stbl"}, a, b)) {
            size_t sa = 0, sb = 0;
            if (child(trak, a, b, "stsd", sa, sb) && sb - sa > 16 && trak.compare(sa + 12, 4, "Opus") == 0) {
                stbl = from + a;
                stblEnd = from + b;
                found = true;
            }
        }
        at = to;
    }
    if (!found) {
        why = "an MP4 file without an Opus track (other codecs aren't supported on Linux)";
        return false;
    }
    size_t sa = 0, sb = 0, entry = 0, entryEnd = 0, ops = 0, opsEnd = 0;
    child(d, stbl, stblEnd, "stsd", sa, sb);
    entry = sa + 8; // version/flags, entry count; then the Opus sample entry box
    entryEnd = entry + be32(d, entry);
    // An audio sample entry: 8 bytes of box header, 28 of fields, then its boxes (dOps).
    if (entryEnd > sb || !child(d, entry + 8 + 28, entryEnd, "dOps", ops, opsEnd) || opsEnd - ops < 11) {
        why = "an Opus track without its dOps box";
        return false;
    }
    unsigned channels = uint8_t(d[ops + 1]);
    unsigned preSkip = (unsigned(uint8_t(d[ops + 2])) << 8) | uint8_t(d[ops + 3]);
    if (channels < 1 || channels > 2) {
        why = "Opus with " + std::to_string(channels) + " channels isn't supported";
        return false;
    }
    // Sample sizes, chunk offsets and how many samples each chunk holds.
    size_t za = 0, zb = 0, ca = 0, cb = 0, ta = 0, tb = 0;
    bool co64 = false;
    if (!child(d, stbl, stblEnd, "stsz", za, zb) || !child(d, stbl, stblEnd, "stsc", ta, tb)
        || (!child(d, stbl, stblEnd, "stco", ca, cb) && !(co64 = child(d, stbl, stblEnd, "co64", ca, cb)))) {
        why = "an Opus track without its sample tables";
        return false;
    }
    uint32_t fixed = be32(d, za + 4), count = be32(d, za + 8);
    uint32_t chunks = be32(d, ca + 4), runs = be32(d, ta + 4);
    std::vector<uint64_t> offsets;
    size_t sample = 0;
    for (uint32_t c = 0; c < chunks; c++) {
        uint64_t offset = co64 ? be64(d, ca + 8 + c * 8) : be32(d, ca + 8 + c * 4);
        uint32_t perChunk = 0;
        for (uint32_t r = 0; r < runs; r++) {
            if (be32(d, ta + 8 + r * 12) <= c + 1) {
                perChunk = be32(d, ta + 8 + r * 12 + 4);
            }
        }
        for (uint32_t s = 0; s < perChunk && sample < count; s++, sample++) {
            offsets.push_back(offset);
            offset += fixed != 0 ? fixed : be32(d, za + 12 + sample * 4);
        }
    }
    Opus &o = opus();
    if (!o.ok) {
        why = "playing Opus needs libopus (libopus0), which isn't installed";
        return false;
    }
    int error = 0;
    void *decoder = o.create(48000, static_cast<int>(channels), &error);
    if (decoder == nullptr) {
        why = "libopus couldn't start (error " + std::to_string(error) + ")";
        return false;
    }
    std::vector<float> frame(5760 * channels);
    for (size_t i = 0; i < offsets.size(); i++) {
        uint32_t size = fixed != 0 ? fixed : be32(d, za + 12 + i * 4);
        if (offsets[i] + size > d.size()) {
            break;
        }
        int frames = o.decode(decoder, reinterpret_cast<const unsigned char *>(d.data() + offsets[i]), static_cast<int32_t>(size), frame.data(), 5760, 0);
        if (frames > 0) {
            out.samples.insert(out.samples.end(), frame.begin(), frame.begin() + frames * channels);
        }
    }
    o.destroy(decoder);
    size_t skip = std::min<size_t>(out.samples.size(), size_t(preSkip) * channels);
    out.samples.erase(out.samples.begin(), out.samples.begin() + static_cast<std::ptrdiff_t>(skip));
    out.channels = channels;
    out.rate = 48000;
    if (out.samples.empty()) {
        why = "the Opus track had no sound";
        return false;
    }
    return true;
}

} // namespace rk::audio

#endif
