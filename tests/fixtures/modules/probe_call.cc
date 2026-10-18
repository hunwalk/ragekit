// Loads a module library and calls its generated rkm_<name>_call entry: one line per call,
// "<ok>|<result>" with the result's tabs/newlines/backslashes escaped.
#include <dlfcn.h>
#include <cstdio>
#include <string>
#include <vector>

using CallFn = const char *(*)(const char *, const char *const *, const size_t *, size_t, int *);

static void call(CallFn fn, const char *function, std::vector<std::string> args)
{
    std::vector<const char *> ptrs;
    std::vector<size_t> lens;
    for (auto &a : args) {
        ptrs.push_back(a.data());
        lens.push_back(a.size());
    }
    int ok = 0;
    std::string out = fn(function, ptrs.data(), lens.data(), args.size(), &ok);
    std::string escaped;
    for (char c : out) {
        escaped += c == '\t' ? "\\t" : c == '\n' ? "\\n" : c == '\\' ? "\\\\" : std::string(1, c);
    }
    std::printf("%d|%s\n", ok, escaped.c_str());
}

int main(int, char **argv)
{
    void *lib = dlopen(argv[1], RTLD_NOW);
    if (lib == nullptr) {
        std::printf("dlopen failed: %s\n", dlerror());
        return 1;
    }
    auto fn = reinterpret_cast<CallFn>(dlsym(lib, "rkm_demo_call"));
    call(fn, "demo_echo", {"a\tb é", "2"});
    call(fn, "demo_add", {"0.1", "0.2", "1"});
    call(fn, "demo_ping", {});
    call(fn, "demo_nope", {});
    call(fn, "demo_echo", {"x"});
    call(fn, "demo_echo", {"x", "-1"});
}
