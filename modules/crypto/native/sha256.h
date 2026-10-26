// SHA-256 (FIPS 180-4): Monocypher has SHA-512 but not SHA-256, which SSH fingerprints and the
// dev-null API's file hashes need.
#pragma once

#include <array>
#include <cstddef>
#include <cstdint>

namespace rk::crypto {

std::array<uint8_t, 32> sha256(const uint8_t *data, size_t len);

} // namespace rk::crypto
