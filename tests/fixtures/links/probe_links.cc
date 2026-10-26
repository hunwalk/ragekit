// rk_links.cc on its own (it's Slint-free): probe_links register <scheme> | claim <schemes>
// For tests/Slint/LinksNativeTest.php; rk_post_event prints instead of reaching PHP.
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <string>

extern "C" {
int rk_links_claim(const char *schemes);
void rk_links_stop(void);
size_t rk_links_register(const char *scheme, char *error, size_t size);
void rk_post_event(const char *module, int64_t, const char *kind, const char *data, size_t len)
{
    std::printf("event %s %s %.*s\n", module, kind, static_cast<int>(len), data);
}
}

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
    return 2;
}
