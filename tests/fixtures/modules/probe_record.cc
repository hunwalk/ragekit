// Records with the audio module's library for 1.5 s (RAGEKIT_AUDIO_NULL=1: the null device, silence)
// and prints the result and how many "level" events came: probe_record <library> <wav path>
#include <dlfcn.h>

#include <atomic>
#include <chrono>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <string>
#include <thread>

using PostFn = void (*)(const char *, int64_t, const char *, const char *, size_t);
std::atomic<int> levels{0};

void post(const char *, int64_t handle, const char *kind, const char *, size_t)
{
    if (handle == 0 && std::strcmp(kind, "level") == 0) {
        levels++;
    }
}

int main(int, char **argv)
{
    void *lib = dlopen(argv[1], RTLD_NOW);
    if (lib == nullptr) {
        std::printf("dlopen failed: %s\n", dlerror());
        return 1;
    }
    auto init = reinterpret_cast<void (*)(PostFn)>(dlsym(lib, "rkm_audio_init"));
    auto reset = reinterpret_cast<void (*)()>(dlsym(lib, "rkm_audio_reset"));
    auto start = reinterpret_cast<int64_t (*)()>(dlsym(lib, "rkm_audio_record_start"));
    auto stop = reinterpret_cast<const char *(*)(const char *, size_t)>(dlsym(lib, "rkm_audio_record_stop"));
    auto error = reinterpret_cast<const char *(*)()>(dlsym(lib, "rkm_audio_record_error"));
    init(post);
    std::printf("stop-before-start [%s] [%s]\n", stop("x", 1), error());
    std::printf("start %lld [%s]\n", static_cast<long long>(start()), error());
    std::this_thread::sleep_for(std::chrono::milliseconds(1500));
    std::string path = argv[2];
    std::string result = stop(path.data(), path.size());
    std::printf("result %s\n", result.c_str());
    std::printf("levels %d\n", levels.load());
    reset();
    return 0;
}
