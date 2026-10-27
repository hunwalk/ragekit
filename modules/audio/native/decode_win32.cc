// rk::audio::decode on Windows (decode.h): Media Foundation's Source Reader, asked for float PCM,
// reads what Windows has decoders for: Opus and AAC in MP4 (M4A), WMA, …
#ifdef _WIN32

#include "decode.h"

#define NOMINMAX
#include <windows.h>
#include <mfapi.h>
#include <mfidl.h>
#include <mfreadwrite.h>

#pragma comment(lib, "mfplat.lib")
#pragma comment(lib, "mfreadwrite.lib")
#pragma comment(lib, "mfuuid.lib")
#pragma comment(lib, "ole32.lib")

namespace rk::audio {

bool decode(const std::string &path, Decoded &out, std::string &why)
{
    std::wstring file(static_cast<size_t>(MultiByteToWideChar(CP_UTF8, 0, path.c_str(), -1, nullptr, 0)), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, path.c_str(), -1, file.data(), static_cast<int>(file.size()));
    bool initialized = SUCCEEDED(CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED));
    MFStartup(MF_VERSION, MFSTARTUP_LITE);
    IMFSourceReader *reader = nullptr;
    IMFMediaType *want = nullptr, *got = nullptr;
    bool ok = false;
    if (FAILED(MFCreateSourceReaderFromURL(file.c_str(), nullptr, &reader))) {
        why = "Windows can't read this file";
    } else if (FAILED(MFCreateMediaType(&want)) || FAILED(want->SetGUID(MF_MT_MAJOR_TYPE, MFMediaType_Audio))
               || FAILED(want->SetGUID(MF_MT_SUBTYPE, MFAudioFormat_Float))
               || FAILED(reader->SetCurrentMediaType(MF_SOURCE_READER_FIRST_AUDIO_STREAM, nullptr, want))
               || FAILED(reader->GetCurrentMediaType(MF_SOURCE_READER_FIRST_AUDIO_STREAM, &got))) {
        why = "Windows has no decoder for this file's sound";
    } else {
        UINT32 rate = 0, channels = 0;
        got->GetUINT32(MF_MT_AUDIO_SAMPLES_PER_SECOND, &rate);
        got->GetUINT32(MF_MT_AUDIO_NUM_CHANNELS, &channels);
        for (;;) {
            DWORD flags = 0;
            IMFSample *sample = nullptr;
            if (FAILED(reader->ReadSample(MF_SOURCE_READER_FIRST_AUDIO_STREAM, 0, nullptr, &flags, nullptr, &sample))
                || (flags & MF_SOURCE_READERF_ENDOFSTREAM)) {
                if (sample != nullptr) {
                    sample->Release();
                }
                break;
            }
            if (sample == nullptr) {
                continue;
            }
            IMFMediaBuffer *buffer = nullptr;
            if (SUCCEEDED(sample->ConvertToContiguousBuffer(&buffer))) {
                BYTE *data = nullptr;
                DWORD length = 0;
                if (SUCCEEDED(buffer->Lock(&data, nullptr, &length))) {
                    const float *floats = reinterpret_cast<const float *>(data);
                    out.samples.insert(out.samples.end(), floats, floats + length / sizeof(float));
                    buffer->Unlock();
                }
                buffer->Release();
            }
            sample->Release();
        }
        out.rate = rate;
        out.channels = channels;
        ok = rate > 0 && channels > 0 && !out.samples.empty();
        if (!ok) {
            why = "the file had no sound";
        }
    }
    if (got != nullptr) {
        got->Release();
    }
    if (want != nullptr) {
        want->Release();
    }
    if (reader != nullptr) {
        reader->Release();
    }
    MFShutdown();
    if (initialized) {
        CoUninitialize();
    }
    return ok;
}

} // namespace rk::audio

#endif
