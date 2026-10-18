// The bridge's module loader on its own (rk_modules.cc): LoaderTest checks each line.
#include "ragekit_slint.h"

#include <cstdio>
#include <string>

int main(int argc, char **argv)
{
    if (argc > 1) {
        // The app expects functions the library may lack: argv[1] = newline-separated symbols.
        rk_module_expect("demo", argv[1]);
        std::printf("open-expected %d\n", rk_module_open("demo") != 0 ? 1 : 0);
        std::printf("expected-error %s\n", rk_module_error("demo"));
        return 0;
    }
    int64_t demo = rk_module_open("demo");
    std::printf("open-demo %d\n", demo != 0 ? 1 : 0);
    std::printf("same-handle %d\n", rk_module_open("demo") == demo ? 1 : 0);
    std::printf("symbol %d\n", rk_module_symbol(demo, "rkm_demo_add") != nullptr ? 1 : 0);
    std::printf("missing-symbol %d\n", rk_module_symbol(demo, "rkm_demo_gone") == nullptr ? 1 : 0);
    std::printf("demo-error [%s]\n", rk_module_error("demo"));
    std::printf("open-nope %d\n", rk_module_open("nope") == 0 ? 1 : 0);
    std::printf("nope-error %s\n", rk_module_error("nope"));
    char dir[4096];
    size_t len = rk_app_dir(dir, sizeof dir);
    std::printf("app-dir %s\n", std::string(dir, len).c_str());
}
