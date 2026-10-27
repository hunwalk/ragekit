// Decodes each file named on the command line with rk::audio::decode (decode_opus.cc) and prints
// one line per file: "ok <samples>" or "no <why>". Built with AddressSanitizer by OpusDecodeTest.
#include "decode.h"

#include <cstdio>

int main(int argc, char **argv)
{
    for (int i = 1; i < argc; i++) {
        rk::audio::Decoded out;
        std::string why;
        if (rk::audio::decode(argv[i], out, why)) {
            std::printf("ok %zu\n", out.samples.size());
        } else {
            std::printf("no %s\n", why.c_str());
        }
    }
    return 0;
}
