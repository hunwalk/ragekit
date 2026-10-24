// Drives the audio module's library like PHP would (AudioLibraryTest): one "name value" line per
// check, and "event <handle> <kind> <data>" lines from the library's event callback.
#include <dlfcn.h>
#include <cstdlib>

#include <chrono>
#include <cmath>
#include <cstdio>
#include <mutex>
#include <string>
#include <thread>

typedef void (*rk_post_fn)(const char *, int64_t, const char *, const char *, size_t);

std::mutex printing;

void say(const std::string &line)
{
    std::lock_guard<std::mutex> lock(printing);
    std::printf("%s\n", line.c_str());
    std::fflush(stdout);
}

void post(const char *module, int64_t handle, const char *kind, const char *data, size_t len)
{
    say(std::string("event ") + module + " " + std::to_string(handle) + " " + kind + " " + std::string(data, len));
}

void sleepMs(int ms) { std::this_thread::sleep_for(std::chrono::milliseconds(ms)); }

std::string num(double v)
{
    char buffer[32];
    std::snprintf(buffer, sizeof buffer, "%.3f", v);
    return buffer;
}

int main(int, char **argv)
{
    // Registered before the library loads, so it runs after the library's static destructors at
    // exit: the process keeps living for a while, as a real app does while it tears down. Audio
    // threads still running then would read freed sounds.
    std::atexit([] { sleepMs(200); });
    void *lib = dlopen(argv[1], RTLD_NOW);
    if (lib == nullptr) {
        std::printf("dlopen failed: %s\n", dlerror());
        return 1;
    }
#define FN(name, type) auto name = reinterpret_cast<type>(dlsym(lib, "rkm_audio_" #name))
    FN(init, void (*)(rk_post_fn));
    FN(reset, void (*)());
    FN(load, int64_t (*)(const char *, size_t));
    FN(play, void (*)(int64_t));
    FN(pause, void (*)(int64_t));
    FN(stop, void (*)(int64_t));
    FN(seek, void (*)(int64_t, double));
    FN(volume, void (*)(int64_t, double));
    FN(position, double (*)(int64_t));
    FN(duration, double (*)(int64_t));
    FN(playing, int (*)(int64_t));
    FN(unload, void (*)(int64_t));

    std::string path = argv[2];
    std::string missing = path + ".missing";
    init(post);
    int64_t s = load(path.data(), path.size());
    say("load " + std::to_string(s));
    say("load-missing " + std::to_string(load(missing.data(), missing.size())));
    say("duration " + num(duration(s)));

    play(s);
    sleepMs(300);
    say("playing " + std::to_string(playing(s)));
    say("position " + num(position(s)));

    pause(s);
    sleepMs(50);
    double held = position(s);
    sleepMs(300);
    say("paused-playing " + std::to_string(playing(s)));
    say("pause-drift " + num(std::fabs(position(s) - held)));

    seek(s, 0.1);
    say("seek-position " + num(position(s)));
    seek(s, NAN);
    seek(s, INFINITY);
    volume(s, NAN);
    volume(s, INFINITY);
    say("seek-nonfinite-position " + num(position(s)));
    say("mark finish-window");
    play(s);
    sleepMs(800);
    say("mark finish-window-end");

    stop(s);
    sleepMs(50);
    say("stop-position " + num(position(s)));
    say("stop-playing " + std::to_string(playing(s)));

    play(99);
    say("stale-position " + num(position(99)));
    say("stale-playing " + std::to_string(playing(99)));

    play(s);
    for (int i = 0; i < 200; ++i) {
        switch (i % 5) {
        case 0: pause(s); break;
        case 1: play(s); break;
        case 2: seek(s, 0.05 * (i % 7)); break;
        case 3: volume(s, (i % 10) / 10.0); break;
        default: position(s); break;
        }
    }
    seek(s, 0.0);
    volume(s, 1.0);
    say("mark hammer");
    play(s);
    sleepMs(1000);
    say("mark hammer-end");

    int64_t second = load(path.data(), path.size());
    play(second);
    unload(second);
    say("unloaded " + std::to_string(second));
    sleepMs(500);

    reset();
    say("after-reset-playing " + std::to_string(playing(s)));
    int64_t again = load(path.data(), path.size());
    say("reload " + std::to_string(again > 0 ? 1 : 0));
    reset();
    say("done 1");

    // Ends while a sound plays, without reset(): the library must shut down cleanly on its own.
    int64_t last = load(path.data(), path.size());
    play(last);
    sleepMs(300);
    say("exiting 1");
    return 0;
}
