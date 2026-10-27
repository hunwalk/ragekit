// rk::emoji::draw on Windows: DirectWrite lays the sequence out in Segoe UI Emoji and Direct2D
// draws it with colour fonts enabled into a WIC bitmap, whose pixels become the image. Flags are
// left out (the font has none).
#ifdef _WIN32

#include "emoji.h"

#define NOMINMAX
#include <windows.h>
#include <d2d1_1.h>
#include <dwrite.h>
#include <wincodec.h>

#include <algorithm>
#include <cmath>
#include <string>

#pragma comment(lib, "d2d1.lib")
#pragma comment(lib, "dwrite.lib")
#pragma comment(lib, "ole32.lib")
#pragma comment(lib, "windowscodecs.lib")

namespace {

constexpr float kSize = 96.0f; // drawn this big; Slint scales it down

template <typename T>
void release(T *&object)
{
    if (object != nullptr) {
        object->Release();
        object = nullptr;
    }
}

struct Tools {
    bool ok = false;
    ID2D1Factory *d2d = nullptr;
    IDWriteFactory *dwrite = nullptr;
    IWICImagingFactory *wic = nullptr;
    IDWriteTextFormat *format = nullptr;
};

Tools &tools()
{
    static Tools t = [] {
        Tools l;
        CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED); // the UI thread's apartment; harmless if it has one
        l.ok = SUCCEEDED(D2D1CreateFactory(D2D1_FACTORY_TYPE_SINGLE_THREADED, &l.d2d))
            && SUCCEEDED(DWriteCreateFactory(DWRITE_FACTORY_TYPE_SHARED, __uuidof(IDWriteFactory), reinterpret_cast<IUnknown **>(&l.dwrite)))
            && SUCCEEDED(CoCreateInstance(CLSID_WICImagingFactory, nullptr, CLSCTX_INPROC_SERVER, IID_PPV_ARGS(&l.wic)))
            && SUCCEEDED(l.dwrite->CreateTextFormat(L"Segoe UI Emoji", nullptr, DWRITE_FONT_WEIGHT_NORMAL, DWRITE_FONT_STYLE_NORMAL,
                                                    DWRITE_FONT_STRETCH_NORMAL, kSize, L"", &l.format));
        return l;
    }();
    return t;
}

std::wstring wide(const std::string &utf8)
{
    int n = MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), nullptr, 0);
    std::wstring out(static_cast<size_t>(n), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), out.data(), n);
    return out;
}

} // namespace

namespace rk::emoji {

slint::Image draw(const std::string &sequence)
{
    Tools &t = tools();
    // Windows' emoji font has no flags: they'd be drawn as two letters (🇭🇺 → "HU"). Left out.
    if (!t.ok || sequence.rfind("\xF0\x9F\x87", 0) == 0) {
        return {};
    }
    std::wstring text = wide(sequence);
    IDWriteTextLayout *layout = nullptr;
    if (FAILED(t.dwrite->CreateTextLayout(text.c_str(), static_cast<UINT32>(text.size()), t.format, 10000.0f, 10000.0f, &layout))) {
        return {};
    }
    DWRITE_TEXT_METRICS metrics{};
    layout->GetMetrics(&metrics);
    UINT width = static_cast<UINT>(std::ceil(metrics.widthIncludingTrailingWhitespace));
    UINT height = static_cast<UINT>(std::ceil(metrics.height));
    slint::Image image;
    IWICBitmap *bitmap = nullptr;
    ID2D1RenderTarget *target = nullptr;
    ID2D1SolidColorBrush *brush = nullptr;
    D2D1_RENDER_TARGET_PROPERTIES props = D2D1::RenderTargetProperties(
        D2D1_RENDER_TARGET_TYPE_SOFTWARE, D2D1::PixelFormat(DXGI_FORMAT_B8G8R8A8_UNORM, D2D1_ALPHA_MODE_PREMULTIPLIED));
    if (width > 0 && height > 0
        && SUCCEEDED(t.wic->CreateBitmap(width, height, GUID_WICPixelFormat32bppPBGRA, WICBitmapCacheOnLoad, &bitmap))
        && SUCCEEDED(t.d2d->CreateWicBitmapRenderTarget(bitmap, props, &target))
        && SUCCEEDED(target->CreateSolidColorBrush(D2D1::ColorF(D2D1::ColorF::Black), &brush))) {
        target->BeginDraw();
        target->Clear(D2D1::ColorF(0, 0, 0, 0));
        target->DrawTextLayout(D2D1::Point2F(0, 0), layout, brush, D2D1_DRAW_TEXT_OPTIONS_ENABLE_COLOR_FONT);
        if (SUCCEEDED(target->EndDraw())) {
            slint::SharedPixelBuffer<slint::Rgba8Pixel> pixels(width, height);
            WICRect all{0, 0, static_cast<INT>(width), static_cast<INT>(height)};
            std::basic_string<BYTE> bgra(static_cast<size_t>(width) * height * 4, 0);
            if (SUCCEEDED(bitmap->CopyPixels(&all, width * 4, static_cast<UINT>(bgra.size()), bgra.data()))) {
                slint::Rgba8Pixel *out = pixels.begin();
                for (size_t i = 0; i < static_cast<size_t>(width) * height; i++) {
                    BYTE b = bgra[i * 4], g = bgra[i * 4 + 1], r = bgra[i * 4 + 2], a = bgra[i * 4 + 3];
                    auto straight = [a](BYTE c) { return a == 0 ? BYTE(0) : static_cast<BYTE>(std::min(255, c * 255 / a)); };
                    out[i] = slint::Rgba8Pixel{straight(r), straight(g), straight(b), a};
                }
                image = slint::Image(pixels);
            }
        }
    }
    release(brush);
    release(target);
    release(bitmap);
    release(layout);
    return image;
}

} // namespace rk::emoji

#endif
