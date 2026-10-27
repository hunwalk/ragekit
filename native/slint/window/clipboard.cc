// rk::clip::save_image, the same on every platform (clipboard.h).
#include "clipboard.h"

#include <cstdint>
#include <cstdio>
#include <filesystem>
#include <fstream>

namespace rk::clip {

namespace {

std::filesystem::path utf8_path(const std::string &text)
{
    return std::filesystem::path(std::u8string(reinterpret_cast<const char8_t *>(text.data()), text.size()));
}

} // namespace

std::string save_image(const std::string &folder)
{
    std::string bytes, extension;
    if (!image(bytes, extension)) {
        return "";
    }
    uint64_t hash = 14695981039346656037ull; // FNV-1a: names the file by its content
    for (unsigned char c : bytes) {
        hash = (hash ^ c) * 1099511628211ull;
    }
    char name[48];
    snprintf(name, sizeof name, "pasted-%016llx.", static_cast<unsigned long long>(hash));
    std::error_code error;
    std::filesystem::path dir = utf8_path(folder);
    std::filesystem::create_directories(dir, error);
    std::filesystem::path file = dir / utf8_path(name + extension);
    std::filesystem::path part = file;
    part += ".part";
    {
        std::ofstream out(part, std::ios::binary | std::ios::trunc);
        out.write(bytes.data(), static_cast<std::streamsize>(bytes.size()));
        if (!out) {
            return "";
        }
    }
    std::filesystem::rename(part, file, error); // never a half-written file under the real name
    if (error) {
        std::filesystem::remove(part, error);
        return "";
    }
    std::u8string path = file.u8string();
    return std::string(path.begin(), path.end());
}

} // namespace rk::clip
