// rk::clip on Windows: the "PNG" clipboard format (browsers, the Snipping Tool, Office) as it is;
// otherwise a bitmap (CF_DIB / CF_DIBV5, e.g. PrintScreen) turned into png with WIC; otherwise a
// copied file (CF_HDROP) when it's one image file.
#ifdef _WIN32

#include "clipboard.h"

#include <windows.h>
#include <shellapi.h>
#include <shlwapi.h>
#include <wincodec.h>

#include <fstream>
#include <iterator>
#include <string>

#pragma comment(lib, "user32.lib")
#pragma comment(lib, "shell32.lib")
#pragma comment(lib, "shlwapi.lib")
#pragma comment(lib, "ole32.lib")
#pragma comment(lib, "windowscodecs.lib")

namespace {

const wchar_t *const kImageExtensions[] = {L"png", L"jpg", L"jpeg", L"gif", L"webp", L"bmp"};

/** OpenClipboard, retried briefly: another app may hold it for a moment. */
class Opened {
public:
    Opened()
    {
        for (int i = 0; i < 10 && !open_; i++) {
            open_ = OpenClipboard(nullptr) != 0;
            if (!open_) {
                Sleep(20);
            }
        }
    }
    ~Opened()
    {
        if (open_) {
            CloseClipboard();
        }
    }
    explicit operator bool() const { return open_; }

private:
    bool open_ = false;
};

UINT png_format()
{
    static UINT format = RegisterClipboardFormatW(L"PNG");
    return format;
}

std::wstring lower(std::wstring text)
{
    for (wchar_t &c : text) {
        c = static_cast<wchar_t>(towlower(c));
    }
    return text;
}

/** The one image file copied in Explorer (clipboard open); '' otherwise. */
std::wstring image_file()
{
    HANDLE data = GetClipboardData(CF_HDROP);
    if (data == nullptr) {
        return L"";
    }
    HDROP drop = static_cast<HDROP>(GlobalLock(data));
    if (drop == nullptr) {
        return L"";
    }
    std::wstring path;
    if (DragQueryFileW(drop, 0xFFFFFFFF, nullptr, 0) == 1) {
        UINT n = DragQueryFileW(drop, 0, nullptr, 0);
        path.resize(n + 1);
        DragQueryFileW(drop, 0, path.data(), n + 1);
        path.resize(n);
    }
    GlobalUnlock(data);
    std::wstring ext = lower(path.substr(path.find_last_of(L'.') + 1));
    for (const wchar_t *known : kImageExtensions) {
        if (ext == known) {
            return path;
        }
    }
    return L"";
}

bool copy_global(UINT format, std::string &out)
{
    HANDLE data = GetClipboardData(format);
    if (data == nullptr) {
        return false;
    }
    const char *bytes = static_cast<const char *>(GlobalLock(data));
    if (bytes == nullptr) {
        return false;
    }
    out.assign(bytes, GlobalSize(data));
    GlobalUnlock(data);
    return !out.empty();
}

/** A packed DIB (BITMAPINFOHEADER or V5 + pixels) → png bytes, through WIC's bmp decoder. */
bool dib_to_png(const std::string &dib, std::string &png)
{
    if (dib.size() < sizeof(BITMAPINFOHEADER)) {
        return false;
    }
    const auto *info = reinterpret_cast<const BITMAPINFOHEADER *>(dib.data());
    DWORD colors = info->biClrUsed != 0 ? info->biClrUsed : (info->biBitCount <= 8 ? 1u << info->biBitCount : 0);
    DWORD masks = info->biSize == sizeof(BITMAPINFOHEADER) && info->biCompression == BI_BITFIELDS ? 12 : 0;
    BITMAPFILEHEADER file{};
    file.bfType = 0x4D42; // "BM"
    file.bfSize = static_cast<DWORD>(sizeof file + dib.size());
    file.bfOffBits = static_cast<DWORD>(sizeof file + info->biSize + masks + colors * sizeof(RGBQUAD));
    std::string bmp(reinterpret_cast<const char *>(&file), sizeof file);
    bmp += dib;

    bool initialized = SUCCEEDED(CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED));
    bool ok = false;
    IWICImagingFactory *factory = nullptr;
    IStream *in = SHCreateMemStream(reinterpret_cast<const BYTE *>(bmp.data()), static_cast<UINT>(bmp.size()));
    IStream *out = SHCreateMemStream(nullptr, 0);
    IWICBitmapDecoder *decoder = nullptr;
    IWICBitmapFrameDecode *frame = nullptr;
    IWICBitmapEncoder *encoder = nullptr;
    IWICBitmapFrameEncode *target = nullptr;
    if (in != nullptr && out != nullptr
        && SUCCEEDED(CoCreateInstance(CLSID_WICImagingFactory, nullptr, CLSCTX_INPROC_SERVER, IID_PPV_ARGS(&factory)))
        && SUCCEEDED(factory->CreateDecoderFromStream(in, nullptr, WICDecodeMetadataCacheOnDemand, &decoder))
        && SUCCEEDED(decoder->GetFrame(0, &frame))
        && SUCCEEDED(factory->CreateEncoder(GUID_ContainerFormatPng, nullptr, &encoder))
        && SUCCEEDED(encoder->Initialize(out, WICBitmapEncoderNoCache))
        && SUCCEEDED(encoder->CreateNewFrame(&target, nullptr)) && SUCCEEDED(target->Initialize(nullptr))
        && SUCCEEDED(target->WriteSource(frame, nullptr)) && SUCCEEDED(target->Commit()) && SUCCEEDED(encoder->Commit())) {
        STATSTG stat{};
        LARGE_INTEGER zero{};
        if (SUCCEEDED(out->Stat(&stat, STATFLAG_NONAME)) && SUCCEEDED(out->Seek(zero, STREAM_SEEK_SET, nullptr))) {
            png.resize(static_cast<size_t>(stat.cbSize.QuadPart));
            ULONG read = 0;
            ok = SUCCEEDED(out->Read(png.data(), static_cast<ULONG>(png.size()), &read)) && read == png.size();
        }
    }
    for (IUnknown *object : {static_cast<IUnknown *>(target), static_cast<IUnknown *>(encoder), static_cast<IUnknown *>(frame),
                             static_cast<IUnknown *>(decoder), static_cast<IUnknown *>(factory), static_cast<IUnknown *>(out),
                             static_cast<IUnknown *>(in)}) {
        if (object != nullptr) {
            object->Release();
        }
    }
    if (initialized) {
        CoUninitialize();
    }
    return ok;
}

std::string narrow(const std::wstring &text)
{
    int n = WideCharToMultiByte(CP_UTF8, 0, text.c_str(), static_cast<int>(text.size()), nullptr, 0, nullptr, nullptr);
    std::string out(static_cast<size_t>(n), '\0');
    WideCharToMultiByte(CP_UTF8, 0, text.c_str(), static_cast<int>(text.size()), out.data(), n, nullptr, nullptr);
    return out;
}

} // namespace

namespace rk::clip {

bool has_image()
{
    if (IsClipboardFormatAvailable(png_format()) || IsClipboardFormatAvailable(CF_DIBV5) || IsClipboardFormatAvailable(CF_DIB)) {
        return true;
    }
    if (!IsClipboardFormatAvailable(CF_HDROP)) {
        return false;
    }
    Opened open;
    return open && !image_file().empty();
}

bool image(std::string &bytes, std::string &extension)
{
    bytes.clear();
    std::wstring file;
    {
        Opened open;
        if (!open) {
            return false;
        }
        if (copy_global(png_format(), bytes)) {
            extension = "png";
            return true;
        }
        std::string dib;
        if (copy_global(CF_DIB, dib) || copy_global(CF_DIBV5, dib)) { // CF_DIB first: a V5 one's alpha is often all zero
            extension = "png";
            return dib_to_png(dib, bytes);
        }
        file = image_file();
    }
    if (file.empty()) {
        return false;
    }
    std::ifstream in(file.c_str(), std::ios::binary);
    bytes.assign(std::istreambuf_iterator<char>(in), std::istreambuf_iterator<char>());
    extension = narrow(lower(file.substr(file.find_last_of(L'.') + 1)));
    if (extension == "jpeg") {
        extension = "jpg";
    }
    return !bytes.empty();
}

} // namespace rk::clip

#endif
