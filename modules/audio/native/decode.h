// Files miniaudio can't open itself, decoded to float PCM in memory: Opus in MP4 (voice notes
// recorded in browsers, as dev-null's server stores them) through libopus on Linux (loaded at
// runtime), and anything Media Foundation reads on Windows (Opus, AAC/M4A, …). One file per
// platform: decode_opus.cc, decode_win32.cc.
#pragma once

#include <string>
#include <vector>

namespace rk::audio {

struct Decoded {
    std::vector<float> samples; // interleaved
    unsigned channels = 0;
    unsigned rate = 0;
};

/** Decodes the whole file; false + why when it can't. */
bool decode(const std::string &path, Decoded &out, std::string &why);

} // namespace rk::audio
