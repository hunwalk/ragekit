// One credential store per OS (store_libsecret.cc, store_wincred.cc), each file wrapped whole in
// #if since the build compiles every file in native/. Errors are messages; '' means none.
#pragma once

#include <string>

namespace rk::keyring {

struct Found {
    bool found = false;
    std::string secret; // raw bytes
    std::string error;  // '' unless the store failed
};

std::string available();
std::string set(const std::string &service, const std::string &account, const std::string &secret);
Found get(const std::string &service, const std::string &account);
std::string remove(const std::string &service, const std::string &account);

} // namespace rk::keyring
