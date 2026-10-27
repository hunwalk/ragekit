// The audio module's library: sound playback with miniaudio (public domain / MIT-0).
// RAGEKIT_AUDIO_NULL=1 uses miniaudio's null device (tests, machines without audio).
//
// Events: a ticker thread posts "progress" (data: position in seconds) every 250 ms for each
// playing sound, and "finished" once when a playing sound reaches its end. Recording (handle 0)
// posts "level" about 10 times a second from miniaudio's capture thread, and "full" at 61 s.
#define MINIAUDIO_IMPLEMENTATION
#define MA_NO_ENCODING
#define MA_NO_GENERATION
// Windows: miniaudio initialises COM on the calling thread, the app's UI thread. Its default
// (multithreaded) makes winit's OleInitialize fail once the first window is created (a panic,
// RPC_E_CHANGED_MODE) whenever audio starts before that, e.g. in App::start(). Apartment-threaded
// is what the UI thread needs anyway.
#define MA_COINIT_VALUE 2 /* COINIT_APARTMENTTHREADED */
#include "miniaudio.h"

#include "rk_module_audio.h"
#include "decode.h"

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
    // A file miniaudio can't open (Opus in MP4, …): decoded here, played from memory.
    rk::audio::Decoded decoded;
    std::unique_ptr<ma_audio_buffer> buffer;
    ~Sound()
    {
        if (buffer != nullptr) {
            ma_audio_buffer_uninit(buffer.get()); // after ma_sound_uninit, which the owner calls first
        }
    }
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

void stopRecording(); // below, with the rest of recording

// Stops the ticker and frees every sound, the engine and the context (miniaudio's audio thread
// ends with the engine).
void shutdown()
{
    stopRecording();
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

// Recording: one capture device at a time, 16 kHz mono s16 (miniaudio converts from the device).
const ma_uint32 kRate = 16000;
const size_t kMaxFrames = kRate * 61; // the dev-null server's limit, a minute and a moment
std::mutex recordLock; // guards everything below except what the capture callback reads atomically
ma_context recordContext;
bool recordContextReady = false;
ma_device recorder;
bool recording = false;
std::vector<int16_t> samples;
std::atomic<int> levelPeak{0};
std::atomic<size_t> sinceLevel{0};
std::atomic<bool> fullSent{false};
std::string recordError;

void onCapture(ma_device *, void *, const void *input, ma_uint32 frames)
{
    const int16_t *in = static_cast<const int16_t *>(input);
    bool full = false;
    {
        std::lock_guard<std::mutex> lock(recordLock);
        size_t room = samples.size() < kMaxFrames ? kMaxFrames - samples.size() : 0;
        size_t keep = frames < room ? frames : room;
        samples.insert(samples.end(), in, in + keep);
        full = keep < frames;
    }
    int peak = levelPeak;
    for (ma_uint32 i = 0; i < frames; ++i) {
        int v = std::abs(static_cast<int>(in[i]));
        peak = v > peak ? v : peak;
    }
    levelPeak = peak;
    if ((sinceLevel += frames) >= kRate / 10 && post != nullptr) {
        sinceLevel = 0;
        std::string level = std::to_string(levelPeak.exchange(0) * 100 / 32768);
        post("audio", 0, "level", level.data(), level.size());
    }
    if (full && !fullSent.exchange(true) && post != nullptr) {
        post("audio", 0, "full", "", 0);
    }
}

void stopRecording() // under recordLock's owner rules: call without holding it
{
    bool was;
    {
        std::lock_guard<std::mutex> lock(recordLock);
        was = recording;
        recording = false;
    }
    if (was) {
        ma_device_uninit(&recorder); // waits for the capture thread: onCapture isn't running after this
    }
    std::lock_guard<std::mutex> lock(recordLock);
    if (recordContextReady) {
        ma_context_uninit(&recordContext);
        recordContextReady = false;
    }
}

void put32(std::string &out, uint32_t v)
{
    for (int i = 0; i < 4; ++i) {
        out += static_cast<char>((v >> (8 * i)) & 0xFF);
    }
}

void put16(std::string &out, uint16_t v)
{
    out += static_cast<char>(v & 0xFF);
    out += static_cast<char>(v >> 8);
}

/** A plain 16 kHz mono 16-bit PCM WAV: the format chunk and the samples, nothing else. */
std::string wav(const std::vector<int16_t> &pcm)
{
    std::string out = "RIFF";
    uint32_t bytes = static_cast<uint32_t>(pcm.size() * 2);
    put32(out, 36 + bytes);
    out += "WAVEfmt ";
    put32(out, 16);
    put16(out, 1);         // PCM
    put16(out, 1);         // mono
    put32(out, kRate);
    put32(out, kRate * 2); // bytes per second
    put16(out, 2);         // block align
    put16(out, 16);        // bits
    out += "data";
    put32(out, bytes);
    for (int16_t s : pcm) {
        put16(out, static_cast<uint16_t>(s));
    }
    return out;
}

/** The peak of each of 48 slices, scaled so the loudest is 100 (all 0 for silence). */
std::string waveform(const std::vector<int16_t> &pcm)
{
    const int bars = 48;
    int peaks[bars] = {};
    int loudest = 0;
    for (int b = 0; b < bars; ++b) {
        size_t from = pcm.size() * b / bars, to = pcm.size() * (b + 1) / bars;
        for (size_t i = from; i < to; ++i) {
            int v = std::abs(static_cast<int>(pcm[i]));
            peaks[b] = v > peaks[b] ? v : peaks[b];
        }
        loudest = peaks[b] > loudest ? peaks[b] : loudest;
    }
    std::string out;
    for (int b = 0; b < bars; ++b) {
        out += (b > 0 ? "," : "") + std::to_string(loudest == 0 ? 0 : peaks[b] * 100 / loudest);
    }
    return out;
}

thread_local std::string recordResult;
std::string loadError; // why the last audio_load failed ('' when it didn't); under `state`

// The app normally resets modules when its event loop ends; if it exits some other way, everything
// must still be shut down before the statics above are destroyed: a running std::thread aborts, and
// miniaudio's audio thread would keep mixing sounds that `sounds` has already freed.
struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); }
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_audio_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_audio_reset(void)
{
    shutdown();
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
        // Not a format miniaudio decodes (WAV, MP3, FLAC): Opus in MP4 and, on Windows, what Media
        // Foundation reads, decoded whole into memory (voice notes are short).
        std::string why;
        if (!rk::audio::decode(file, s->decoded, why)) {
            loadError = why;
            return 0;
        }
        ma_audio_buffer_config config = ma_audio_buffer_config_init(ma_format_f32, s->decoded.channels,
            s->decoded.samples.size() / s->decoded.channels, s->decoded.samples.data(), nullptr);
        config.sampleRate = s->decoded.rate;
        s->buffer = std::make_unique<ma_audio_buffer>();
        if (ma_audio_buffer_init(&config, s->buffer.get()) != MA_SUCCESS) {
            s->buffer.reset();
            loadError = "couldn't play the decoded sound";
            return 0;
        }
        if (ma_sound_init_from_data_source(&engine, s->buffer.get(), 0, nullptr, &s->sound) != MA_SUCCESS) {
            loadError = "couldn't play the decoded sound";
            return 0;
        }
    }
    loadError.clear();
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

RKM_API void rkm_audio_speed(int64_t sound, double speed)
{
    std::lock_guard<std::mutex> lock(state);
    if (Sound *s = find(sound)) {
        ma_sound_set_pitch(&s->sound, static_cast<float>(speed > 0.1 ? speed : 0.1));
    }
}

RKM_API int64_t rkm_audio_record_start(void)
{
    stopRecording(); // one at a time: a new start drops a recording in progress
    std::lock_guard<std::mutex> lock(recordLock);
    samples.clear();
    samples.reserve(kRate * 10);
    fullSent = false;
    levelPeak = 0;
    sinceLevel = 0;
    const char *null = std::getenv("RAGEKIT_AUDIO_NULL");
    ma_context *ctx = nullptr;
    if (null != nullptr && std::strcmp(null, "1") == 0) {
        ma_backend backends[] = {ma_backend_null};
        if (ma_context_init(backends, 1, nullptr, &recordContext) != MA_SUCCESS) {
            recordError = "the null audio backend didn't start";
            return 0;
        }
        recordContextReady = true;
        ctx = &recordContext;
    }
    ma_device_config config = ma_device_config_init(ma_device_type_capture);
    config.capture.format = ma_format_s16;
    config.capture.channels = 1;
    config.sampleRate = kRate;
    config.dataCallback = onCapture;
    if (ma_device_init(ctx, &config, &recorder) != MA_SUCCESS) {
        recordError = "no microphone could be opened";
        return 0;
    }
    if (ma_device_start(&recorder) != MA_SUCCESS) {
        ma_device_uninit(&recorder);
        recordError = "the microphone didn't start";
        return 0;
    }
    recording = true;
    recordError.clear();
    return 1;
}

RKM_API const char *rkm_audio_record_stop(const char *path, size_t path_len)
{
    bool was;
    {
        std::lock_guard<std::mutex> lock(recordLock);
        was = recording;
    }
    stopRecording();
    std::lock_guard<std::mutex> lock(recordLock);
    recordResult.clear();
    if (!was) {
        recordError = "nothing was recording";
        return recordResult.c_str();
    }
    std::string file(path, path_len);
#ifdef _WIN32 // untested
    std::wstring wide(MultiByteToWideChar(CP_UTF8, 0, file.c_str(), -1, nullptr, 0), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, file.c_str(), -1, wide.data(), static_cast<int>(wide.size()));
    FILE *out = _wfopen(wide.c_str(), L"wb");
#else
    FILE *out = std::fopen(file.c_str(), "wb");
#endif
    std::string bytes = wav(samples);
    if (out == nullptr || std::fwrite(bytes.data(), 1, bytes.size(), out) != bytes.size()) {
        if (out != nullptr) {
            std::fclose(out);
        }
        recordError = "couldn't write " + file;
        return recordResult.c_str();
    }
    std::fclose(out);
    char seconds[32];
    std::snprintf(seconds, sizeof seconds, "%.3f", static_cast<double>(samples.size()) / kRate);
    recordResult = std::string(seconds) + "\n" + waveform(samples);
    samples.clear();
    return recordResult.c_str();
}

RKM_API void rkm_audio_record_cancel(void)
{
    stopRecording();
    std::lock_guard<std::mutex> lock(recordLock);
    samples.clear();
}

RKM_API const char *rkm_audio_load_error(void)
{
    std::lock_guard<std::mutex> lock(state);
    recordResult = loadError;
    return recordResult.c_str();
}

RKM_API const char *rkm_audio_record_error(void)
{
    std::lock_guard<std::mutex> lock(recordLock);
    recordResult = recordError;
    return recordResult.c_str();
}

} // extern "C"
