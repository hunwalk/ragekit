// The audio module's library: sound playback with miniaudio (public domain / MIT-0).
// RAGEKIT_AUDIO_NULL=1 uses miniaudio's null device (tests, machines without audio).
//
// Events: a ticker thread posts "progress" (data: position in seconds) every 250 ms for each
// playing sound, and "finished" once when a playing sound reaches its end.
#define MINIAUDIO_IMPLEMENTATION
#define MA_NO_ENCODING
#define MA_NO_GENERATION
#include "miniaudio.h"

#include "rk_module_audio.h"

#include <atomic>
#include <chrono>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <map>
#include <memory>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

#ifdef _WIN32
#include <windows.h>
#endif

namespace {

struct Sound {
    ma_sound sound;
    bool wasPlaying = false;   // playing at the last tick (or started since)
    bool finishedSent = false; // "finished" posted for the current run to the end
};

struct Event {
    int64_t handle;
    const char *kind;
    std::string data;
};

// Lock order: posting, then state. `posting` is held while events are collected and sent, so
// unload()/reset() return only after events for the sounds they free are out.
std::mutex posting;
std::mutex state;
ma_context context;
bool contextReady = false;
ma_engine engine;
bool engineReady = false;
std::map<int64_t, std::unique_ptr<Sound>> sounds;
int64_t nextId = 1;
rk_post_fn post = nullptr;
std::thread ticker;
std::atomic<bool> ticking{false};

bool ensureEngine()
{
    if (engineReady) {
        return true;
    }
    ma_engine_config config = ma_engine_config_init();
    const char *null = std::getenv("RAGEKIT_AUDIO_NULL");
    if (null != nullptr && std::strcmp(null, "1") == 0) {
        ma_backend backends[] = {ma_backend_null};
        if (ma_context_init(backends, 1, nullptr, &context) != MA_SUCCESS) {
            return false;
        }
        contextReady = true;
        config.pContext = &context;
    }
    if (ma_engine_init(&config, &engine) != MA_SUCCESS) {
        if (contextReady) {
            ma_context_uninit(&context);
            contextReady = false;
        }
        return false;
    }
    engineReady = true;
    return true;
}

Sound *find(int64_t id)
{
    auto it = sounds.find(id);
    return it == sounds.end() ? nullptr : it->second.get();
}

double cursor(Sound *s)
{
    float seconds = 0;
    return ma_sound_get_cursor_in_seconds(&s->sound, &seconds) == MA_SUCCESS ? seconds : 0.0;
}

void tick()
{
    while (ticking) {
        for (int i = 0; i < 25 && ticking; ++i) {
            std::this_thread::sleep_for(std::chrono::milliseconds(10));
        }
        std::lock_guard<std::mutex> sending(posting);
        std::vector<Event> events;
        {
            std::lock_guard<std::mutex> lock(state);
            for (auto &[id, s] : sounds) {
                bool playing = ma_sound_is_playing(&s->sound);
                if (playing) {
                    char text[32];
                    std::snprintf(text, sizeof text, "%.3f", cursor(s.get()));
                    events.push_back({id, "progress", text});
                } else if (s->wasPlaying && ma_sound_at_end(&s->sound) && !s->finishedSent) {
                    s->finishedSent = true;
                    events.push_back({id, "finished", ""});
                }
                s->wasPlaying = playing;
            }
        }
        if (post != nullptr) {
            for (const auto &e : events) {
                post("audio", e.handle, e.kind, e.data.data(), e.data.size());
            }
        }
    }
}

void startTicker()
{
    if (!ticking) {
        ticking = true;
        ticker = std::thread(tick);
    }
}

void stopTicker()
{
    if (ticking) {
        ticking = false;
        ticker.join();
    }
}

// The app normally resets modules when its event loop ends; if it exits some other way, the
// ticker must still be stopped before `ticker` is destroyed (a running std::thread aborts).
struct ShutdownGuard {
    ~ShutdownGuard() { stopTicker(); }
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_audio_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_audio_reset(void)
{
    stopTicker();
    std::lock_guard<std::mutex> sending(posting);
    std::lock_guard<std::mutex> lock(state);
    for (auto &[id, s] : sounds) {
        ma_sound_uninit(&s->sound);
    }
    sounds.clear();
    if (engineReady) {
        ma_engine_uninit(&engine);
        engineReady = false;
    }
    if (contextReady) {
        ma_context_uninit(&context);
        contextReady = false;
    }
}

RKM_API int64_t rkm_audio_load(const char *path, size_t path_len)
{
    std::lock_guard<std::mutex> lock(state);
    if (!ensureEngine()) {
        return 0;
    }
    auto s = std::make_unique<Sound>();
    std::string file(path, path_len);
#ifdef _WIN32 // Windows: untested
    std::wstring wide(MultiByteToWideChar(CP_UTF8, 0, file.c_str(), -1, nullptr, 0), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, file.c_str(), -1, wide.data(), static_cast<int>(wide.size()));
    ma_result result = ma_sound_init_from_file_w(&engine, wide.c_str(), 0, nullptr, nullptr, &s->sound);
#else
    ma_result result = ma_sound_init_from_file(&engine, file.c_str(), 0, nullptr, nullptr, &s->sound);
#endif
    if (result != MA_SUCCESS) {
        return 0;
    }
    int64_t id = nextId++;
    sounds.emplace(id, std::move(s));
    startTicker();
    return id;
}

RKM_API void rkm_audio_play(int64_t sound)
{
    std::lock_guard<std::mutex> lock(state);
    if (Sound *s = find(sound)) {
        if (ma_sound_at_end(&s->sound)) {
            s->finishedSent = false; // ma_sound_start rewinds a finished sound
        }
        ma_sound_start(&s->sound);
        s->wasPlaying = true;
    }
}

RKM_API void rkm_audio_pause(int64_t sound)
{
    std::lock_guard<std::mutex> lock(state);
    if (Sound *s = find(sound)) {
        ma_sound_stop(&s->sound);
        s->wasPlaying = false;
    }
}

RKM_API void rkm_audio_stop(int64_t sound)
{
    std::lock_guard<std::mutex> lock(state);
    if (Sound *s = find(sound)) {
        ma_sound_stop(&s->sound);
        ma_sound_seek_to_pcm_frame(&s->sound, 0);
        s->wasPlaying = false;
        s->finishedSent = false;
    }
}

RKM_API void rkm_audio_seek(int64_t sound, double seconds)
{
    if (!std::isfinite(seconds)) {
        return; // NaN or infinity: converting it to a frame number is undefined
    }
    std::lock_guard<std::mutex> lock(state);
    if (Sound *s = find(sound)) {
        ma_uint32 rate = 0;
        if (ma_sound_get_data_format(&s->sound, nullptr, nullptr, &rate, nullptr, 0) == MA_SUCCESS && rate > 0) {
            ma_sound_seek_to_pcm_frame(&s->sound, static_cast<ma_uint64>((seconds < 0 ? 0 : seconds) * rate));
            s->finishedSent = false;
        }
    }
}

RKM_API void rkm_audio_volume(int64_t sound, double volume)
{
    if (!std::isfinite(volume)) {
        return;
    }
    std::lock_guard<std::mutex> lock(state);
    if (Sound *s = find(sound)) {
        ma_sound_set_volume(&s->sound, static_cast<float>(volume < 0 ? 0 : volume));
    }
}

RKM_API double rkm_audio_position(int64_t sound)
{
    std::lock_guard<std::mutex> lock(state);
    Sound *s = find(sound);
    return s == nullptr ? 0.0 : cursor(s);
}

RKM_API double rkm_audio_duration(int64_t sound)
{
    std::lock_guard<std::mutex> lock(state);
    float seconds = 0;
    Sound *s = find(sound);
    return s != nullptr && ma_sound_get_length_in_seconds(&s->sound, &seconds) == MA_SUCCESS ? seconds : 0.0;
}

RKM_API int rkm_audio_playing(int64_t sound)
{
    std::lock_guard<std::mutex> lock(state);
    Sound *s = find(sound);
    return s != nullptr && ma_sound_is_playing(&s->sound) ? 1 : 0;
}

RKM_API void rkm_audio_unload(int64_t sound)
{
    std::lock_guard<std::mutex> sending(posting);
    std::lock_guard<std::mutex> lock(state);
    auto it = sounds.find(sound);
    if (it != sounds.end()) {
        ma_sound_uninit(&it->second->sound);
        sounds.erase(it);
    }
}

} // extern "C"
