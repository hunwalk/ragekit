// rk_links.cc on its own (it's Slint-free): probe_links register <scheme> | claim <schemes> |
// serve <schemes> <ms> (claims, then listens that long)
// For tests/Slint/LinksNativeTest.php; rk_post_event prints instead of reaching PHP.
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <chrono>
#include <string>
#include <thread>

extern "C" {
int rk_links_claim(const char *schemes);
void rk_links_stop(void);
size_t rk_links_register(const char *scheme, char *error, size_t size);
void rk_post_event(const char *module, int64_t, const char *kind, const char *data, size_t len)
{
    std::printf("event %s %s %.*s\n", module, kind, static_cast<int>(len), data);
    std::fflush(stdout);
}
}

// The window layer's part (native/slint/window/activation.h), without Slint.
namespace rk::win {
void prefer_x11()
{
}
void set_activation_token(const std::string &token)
{
    std::printf("token %s\n", token.c_str());
    std::fflush(stdout);
}
std::string take_activation_token()
{
    return "";
}
} // namespace rk::win

int main(int argc, char **argv)
{
    if (argc >= 3 && std::strcmp(argv[1], "register") == 0) {
        char error[512];
        size_t len = rk_links_register(argv[2], error, sizeof error);
        std::printf("register [%.*s]\n", static_cast<int>(len), error);
        return 0;
    }
    if (argc >= 3 && std::strcmp(argv[1], "claim") == 0) {
        std::printf("claim %d\n", rk_links_claim(argv[2]));
        rk_links_stop();
        return 0;
    }
    if (argc >= 4 && std::strcmp(argv[1], "serve") == 0) {
        std::printf("claim %d\n", rk_links_claim(argv[2]));
        std::fflush(stdout);
        std::this_thread::sleep_for(std::chrono::milliseconds(std::atoi(argv[3])));
        rk_links_stop();
        return 0;
    }
    return 2;
}
