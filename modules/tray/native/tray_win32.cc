// The tray module's library on Windows: one icon in the taskbar's notification area
// (Shell_NotifyIconW, NOTIFYICON_VERSION_4). A worker thread owns a hidden window that receives the
// icon's messages and runs the menu (TrackPopupMenuEx); calls from PHP update the shared state and
// SendMessage the window, so every Shell_NotifyIcon call happens on that thread. The image file is
// decoded with WIC (png, ico, bmp, …), or drawn with Direct2D (svg), at the taskbar's small-icon size. A left click (or Enter /
// Space on the focused icon) posts "activate", the menu (right click, Shift+F10) posts "menu" with
// the item's index, both for handle 1. The icon comes back by itself when Explorer restarts.
#ifdef _WIN32

#include "rk_module_tray.h"

#include <windows.h>
#include <shellapi.h>
#include <windowsx.h>
#include <wincodec.h>
#include <d2d1_3.h>
#include <shlwapi.h>

#include <condition_variable>
#include <cstdint>
#include <mutex>
#include <sstream>
#include <string>
#include <thread>
#include <vector>

namespace {

const UINT kCallback = WM_APP + 1; // the icon's messages
const UINT kUpdate = WM_APP + 2;   // wParam: what changed (kIcon | kTip)
const UINT kQuit = WM_APP + 3;
const WPARAM kIcon = 1, kTip = 2;

rk_post_fn post = nullptr;

std::mutex lock; // guards everything below
std::wstring iconPath, tooltip;
std::vector<std::wstring> menu; // labels in order; L"-" = separator
std::string lastError;
HWND window = nullptr;
std::thread worker;
thread_local std::string result;

// Only the worker thread touches these.
HICON icon = nullptr;
bool added = false;
UINT taskbarCreated = 0;

const char *answer(std::string text)
{
    result = std::move(text);
    return result.c_str();
}

std::wstring wide(const char *utf8, size_t len)
{
    if (len == 0) {
        return std::wstring();
    }
    int n = MultiByteToWideChar(CP_UTF8, 0, utf8, static_cast<int>(len), nullptr, 0);
    std::wstring out(static_cast<size_t>(n), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, utf8, static_cast<int>(len), out.data(), n);
    return out;
}

std::string narrow(const std::wstring &text)
{
    if (text.empty()) {
        return std::string();
    }
    int n = WideCharToMultiByte(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), nullptr, 0, nullptr, nullptr);
    std::string out(static_cast<size_t>(n), '\0');
    WideCharToMultiByte(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), out.data(), n, nullptr, nullptr);
    return out;
}

template <typename T>
void release(T *&com)
{
    if (com != nullptr) {
        com->Release();
        com = nullptr;
    }
}

/** An SVG file drawn into a size×size bitmap with Direct2D (Windows 10 1703 and later). */
HRESULT drawSvg(IWICImagingFactory *factory, const std::wstring &path, int size, IWICBitmap **out)
{
    ID2D1Factory *d2d = nullptr;
    ID2D1RenderTarget *target = nullptr;
    ID2D1DeviceContext5 *context = nullptr;
    IStream *stream = nullptr;
    ID2D1SvgDocument *svg = nullptr;
    HRESULT hr = factory->CreateBitmap(static_cast<UINT>(size), static_cast<UINT>(size), GUID_WICPixelFormat32bppPBGRA,
                                       WICBitmapCacheOnLoad, out);
    if (SUCCEEDED(hr)) {
        hr = D2D1CreateFactory(D2D1_FACTORY_TYPE_SINGLE_THREADED, __uuidof(ID2D1Factory), nullptr, reinterpret_cast<void **>(&d2d));
    }
    if (SUCCEEDED(hr)) {
        D2D1_RENDER_TARGET_PROPERTIES props = D2D1::RenderTargetProperties(
            D2D1_RENDER_TARGET_TYPE_SOFTWARE, D2D1::PixelFormat(DXGI_FORMAT_B8G8R8A8_UNORM, D2D1_ALPHA_MODE_PREMULTIPLIED));
        hr = d2d->CreateWicBitmapRenderTarget(*out, props, &target);
    }
    if (SUCCEEDED(hr)) {
        hr = target->QueryInterface(__uuidof(ID2D1DeviceContext5), reinterpret_cast<void **>(&context));
    }
    if (SUCCEEDED(hr)) {
        hr = SHCreateStreamOnFileEx(path.c_str(), STGM_READ | STGM_SHARE_DENY_WRITE, FILE_ATTRIBUTE_NORMAL, FALSE, nullptr, &stream);
    }
    if (SUCCEEDED(hr)) {
        hr = context->CreateSvgDocument(stream, D2D1::SizeF(static_cast<float>(size), static_cast<float>(size)), &svg);
    }
    if (SUCCEEDED(hr)) {
        // Fit the picture to the icon: Direct2D would draw it at its own width and height.
        ID2D1SvgElement *root = nullptr;
        svg->GetRoot(&root);
        if (root != nullptr) {
            D2D1_SVG_LENGTH width = {0, D2D1_SVG_LENGTH_UNITS_NUMBER}, height = width;
            if (!root->IsAttributeSpecified(L"viewBox") && SUCCEEDED(root->GetAttributeValue(L"width", &width))
                && SUCCEEDED(root->GetAttributeValue(L"height", &height)) && width.units == D2D1_SVG_LENGTH_UNITS_NUMBER
                && height.units == D2D1_SVG_LENGTH_UNITS_NUMBER) {
                D2D1_SVG_VIEWBOX box = {0, 0, width.value, height.value};
                root->SetAttributeValue(L"viewBox", D2D1_SVG_ATTRIBUTE_POD_TYPE_VIEWBOX, &box, sizeof box);
            }
            D2D1_SVG_LENGTH full = {100, D2D1_SVG_LENGTH_UNITS_PERCENTAGE};
            root->SetAttributeValue(L"width", full);
            root->SetAttributeValue(L"height", full);
            root->Release();
        }
        context->BeginDraw();
        context->Clear(D2D1::ColorF(0, 0, 0, 0));
        context->DrawSvgDocument(svg);
        hr = context->EndDraw();
    }
    release(svg);
    release(stream);
    release(context);
    release(target);
    release(d2d);
    if (FAILED(hr)) {
        release(*out);
    }
    return hr;
}

/** The image file as an icon of size×size pixels; nullptr and `why` when it can't be read. */
HICON loadIcon(const std::wstring &path, int size, std::string &why)
{
    IWICImagingFactory *factory = nullptr;
    IWICBitmapDecoder *decoder = nullptr;
    IWICBitmapFrameDecode *frame = nullptr;
    IWICBitmapScaler *scaler = nullptr;
    IWICFormatConverter *converter = nullptr;
    IWICBitmap *drawn = nullptr;
    IWICBitmapSource *pixels = nullptr; // size×size, premultiplied BGRA
    HICON out = nullptr;
    bool svg = path.size() > 4 && _wcsicmp(path.c_str() + path.size() - 4, L".svg") == 0;
    HRESULT hr = CoCreateInstance(CLSID_WICImagingFactory, nullptr, CLSCTX_INPROC_SERVER, IID_PPV_ARGS(&factory));
    if (FAILED(hr)) {
        why = "Windows Imaging Component isn't available";
    } else if (svg) {
        hr = drawSvg(factory, path, size, &drawn);
        if (FAILED(hr)) {
            why = "couldn't draw " + narrow(path) + " (SVG needs Windows 10 1703 or later)";
        }
        pixels = drawn;
    } else {
        hr = factory->CreateDecoderFromFilename(path.c_str(), nullptr, GENERIC_READ, WICDecodeMetadataCacheOnDemand, &decoder);
        if (FAILED(hr)) {
            why = "couldn't read " + narrow(path) + " (Windows reads png, ico, bmp and svg)";
        }
        if (SUCCEEDED(hr)) {
            hr = decoder->GetFrame(0, &frame);
        }
        if (SUCCEEDED(hr)) {
            hr = factory->CreateBitmapScaler(&scaler);
        }
        if (SUCCEEDED(hr)) {
            hr = scaler->Initialize(frame, static_cast<UINT>(size), static_cast<UINT>(size), WICBitmapInterpolationModeFant);
        }
        if (SUCCEEDED(hr)) {
            hr = factory->CreateFormatConverter(&converter);
        }
        if (SUCCEEDED(hr)) {
            hr = converter->Initialize(scaler, GUID_WICPixelFormat32bppPBGRA, WICBitmapDitherTypeNone, nullptr, 0, WICBitmapPaletteTypeCustom);
        }
        pixels = converter;
    }
    if (SUCCEEDED(hr)) {
        BITMAPINFO info = {};
        info.bmiHeader.biSize = sizeof info.bmiHeader;
        info.bmiHeader.biWidth = size;
        info.bmiHeader.biHeight = -size; // top-down
        info.bmiHeader.biPlanes = 1;
        info.bmiHeader.biBitCount = 32;
        info.bmiHeader.biCompression = BI_RGB;
        void *bits = nullptr;
        HDC screen = GetDC(nullptr);
        HBITMAP color = CreateDIBSection(screen, &info, DIB_RGB_COLORS, &bits, nullptr, 0);
        ReleaseDC(nullptr, screen);
        HBITMAP mask = CreateBitmap(size, size, 1, 1, nullptr);
        if (color != nullptr && mask != nullptr
            && SUCCEEDED(pixels->CopyPixels(nullptr, static_cast<UINT>(size) * 4, static_cast<UINT>(size * size * 4), static_cast<BYTE *>(bits)))) {
            ICONINFO parts = {};
            parts.fIcon = TRUE;
            parts.hbmColor = color;
            parts.hbmMask = mask;
            out = CreateIconIndirect(&parts); // copies both bitmaps
        }
        if (color != nullptr) {
            DeleteObject(color);
        }
        if (mask != nullptr) {
            DeleteObject(mask);
        }
    }
    if (out == nullptr && why.empty()) {
        why = "couldn't decode " + narrow(path);
    }
    release(drawn);
    release(converter);
    release(scaler);
    release(frame);
    release(decoder);
    release(factory);
    return out;
}

NOTIFYICONDATAW iconData(HWND hwnd)
{
    NOTIFYICONDATAW data = {};
    data.cbSize = sizeof data;
    data.hWnd = hwnd;
    data.uID = 1;
    return data;
}

/** Adds the icon, or changes it; on the worker thread. */
void apply(HWND hwnd, WPARAM what)
{
    NOTIFYICONDATAW data = iconData(hwnd);
    data.uFlags = NIF_MESSAGE | NIF_ICON | NIF_TIP | NIF_SHOWTIP;
    data.uCallbackMessage = kCallback;
    std::wstring path, tip;
    {
        std::lock_guard<std::mutex> guard(lock);
        path = iconPath;
        tip = tooltip;
    }
    if ((what & kIcon) != 0 || icon == nullptr) {
        UINT dpi = GetDpiForWindow(hwnd);
        std::string why;
        HICON fresh = loadIcon(path, GetSystemMetricsForDpi(SM_CXSMICON, dpi != 0 ? dpi : 96), why);
        if (fresh == nullptr) {
            std::lock_guard<std::mutex> guard(lock);
            lastError = why;
            return;
        }
        if (icon != nullptr) {
            DestroyIcon(icon);
        }
        icon = fresh;
    }
    data.hIcon = icon;
    wcsncpy_s(data.szTip, tip.c_str(), _TRUNCATE);
    if (!added) {
        if (!Shell_NotifyIconW(NIM_ADD, &data)) {
            std::lock_guard<std::mutex> guard(lock);
            lastError = "the taskbar didn't take the icon";
            return;
        }
        data.uVersion = NOTIFYICON_VERSION_4;
        Shell_NotifyIconW(NIM_SETVERSION, &data);
        added = true;
    } else {
        Shell_NotifyIconW(NIM_MODIFY, &data);
    }
    std::lock_guard<std::mutex> guard(lock);
    lastError.clear();
}

/** A menu label: & marks a mnemonic, so a literal one is doubled. */
std::wstring label(const std::wstring &text)
{
    std::wstring out;
    for (wchar_t c : text) {
        out += c == L'&' ? std::wstring(L"&&") : std::wstring(1, c);
    }
    return out;
}

void showMenu(HWND hwnd, int x, int y)
{
    std::vector<std::wstring> items;
    {
        std::lock_guard<std::mutex> guard(lock);
        items = menu;
    }
    if (items.empty()) {
        return;
    }
    HMENU popup = CreatePopupMenu();
    for (size_t i = 0; i < items.size(); i++) {
        if (items[i] == L"-") {
            AppendMenuW(popup, MF_SEPARATOR, 0, nullptr);
        } else {
            AppendMenuW(popup, MF_STRING, i + 1, label(items[i]).c_str());
        }
    }
    // Without the foreground the menu wouldn't close when the user clicks elsewhere.
    SetForegroundWindow(hwnd);
    UINT flags = TPM_RETURNCMD | TPM_NONOTIFY | TPM_RIGHTBUTTON
        | (GetSystemMetrics(SM_MENUDROPALIGNMENT) != 0 ? TPM_RIGHTALIGN : TPM_LEFTALIGN);
    int chosen = static_cast<int>(TrackPopupMenuEx(popup, flags, x, y, hwnd, nullptr));
    PostMessageW(hwnd, WM_NULL, 0, 0);
    DestroyMenu(popup);
    if (chosen > 0 && post != nullptr) {
        std::string index = std::to_string(chosen - 1);
        post("tray", 1, "menu", index.data(), index.size());
    }
}

LRESULT CALLBACK onMessage(HWND hwnd, UINT message, WPARAM wParam, LPARAM lParam)
{
    if (message == kCallback) {
        switch (LOWORD(lParam)) {
        case NIN_SELECT:
        case NIN_KEYSELECT:
            if (post != nullptr) {
                post("tray", 1, "activate", "", 0);
            }
            break;
        case WM_CONTEXTMENU:
            showMenu(hwnd, GET_X_LPARAM(wParam), GET_Y_LPARAM(wParam));
            break;
        default:
            break;
        }
        return 0;
    }
    if (message == kUpdate) {
        apply(hwnd, wParam);
        return 0;
    }
    if (message == kQuit) {
        DestroyWindow(hwnd);
        return 0;
    }
    if (message == taskbarCreated && taskbarCreated != 0) {
        added = false; // Explorer restarted and forgot the icon
        apply(hwnd, 0);
        return 0;
    }
    if (message == WM_DPICHANGED_AFTERPARENT || message == WM_DISPLAYCHANGE) {
        apply(hwnd, kIcon);
    }
    if (message == WM_DESTROY) {
        if (added) {
            NOTIFYICONDATAW data = iconData(hwnd);
            Shell_NotifyIconW(NIM_DELETE, &data);
            added = false;
        }
        if (icon != nullptr) {
            DestroyIcon(icon);
            icon = nullptr;
        }
        PostQuitMessage(0);
        return 0;
    }
    return DefWindowProcW(hwnd, message, wParam, lParam);
}

/** Starts the worker and its window; under lock. '' or an error. */
std::string start()
{
    if (window != nullptr) {
        return "";
    }
    std::mutex readyLock;
    std::condition_variable readyCv;
    bool ready = false;
    HWND created = nullptr;
    worker = std::thread([&readyLock, &readyCv, &ready, &created] {
        HRESULT com = CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED); // WIC
        taskbarCreated = RegisterWindowMessageW(L"TaskbarCreated");
        // This library's own instance: `ragekit dev` reloads it from a new file, and the class must
        // point at the current copy's onMessage (it's unregistered when the worker ends).
        HINSTANCE instance = nullptr;
        GetModuleHandleExW(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT,
                           reinterpret_cast<LPCWSTR>(&onMessage), &instance);
        WNDCLASSEXW cls = {};
        cls.cbSize = sizeof cls;
        cls.lpfnWndProc = onMessage;
        cls.hInstance = instance;
        cls.lpszClassName = L"RageKitTray";
        RegisterClassExW(&cls);
        // A hidden top-level window, not a message-only one: those don't get TaskbarCreated.
        HWND hwnd = CreateWindowExW(0, L"RageKitTray", L"", WS_POPUP, 0, 0, 0, 0, nullptr, nullptr, instance, nullptr);
        if (hwnd != nullptr) {
            ChangeWindowMessageFilterEx(hwnd, taskbarCreated, MSGFLT_ALLOW, nullptr);
        }
        {
            std::lock_guard<std::mutex> guard(readyLock);
            created = hwnd;
            ready = true;
            readyCv.notify_one();
        }
        if (hwnd != nullptr) {
            MSG msg;
            while (GetMessageW(&msg, nullptr, 0, 0) > 0) {
                TranslateMessage(&msg);
                DispatchMessageW(&msg);
            }
        }
        UnregisterClassW(L"RageKitTray", instance);
        if (SUCCEEDED(com)) {
            CoUninitialize();
        }
    });
    std::unique_lock<std::mutex> wait(readyLock);
    readyCv.wait(wait, [&] { return ready; });
    if (created == nullptr) {
        wait.unlock();
        worker.join();
        return "couldn't create the tray's window";
    }
    window = created;
    return "";
}

void shutdown()
{
    std::thread stopping;
    HWND hwnd;
    {
        std::lock_guard<std::mutex> guard(lock);
        hwnd = window;
        window = nullptr;
        stopping = std::move(worker);
        menu.clear();
    }
    if (hwnd != nullptr) {
        PostMessageW(hwnd, kQuit, 0, 0);
    }
    if (stopping.joinable()) {
        stopping.join();
    }
}

/** Sends `what` to the worker (outside the lock: the worker takes it while applying). */
void update(WPARAM what)
{
    HWND hwnd;
    {
        std::lock_guard<std::mutex> guard(lock);
        hwnd = window;
    }
    if (hwnd != nullptr) {
        SendMessageW(hwnd, kUpdate, what, 0);
    }
}

struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); } // a worker thread still running at exit would abort the process
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_tray_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_tray_reset(void)
{
    shutdown();
}

RKM_API const char *rkm_tray_available(void)
{
    return answer(FindWindowW(L"Shell_TrayWnd", nullptr) != nullptr ? "" : "no taskbar (Explorer isn't running)");
}

RKM_API int64_t rkm_tray_show(const char *path, size_t path_len, const char *text, size_t text_len)
{
    {
        std::lock_guard<std::mutex> guard(lock);
        lastError = start();
        if (!lastError.empty()) {
            return 0;
        }
        iconPath = wide(path, path_len);
        tooltip = wide(text, text_len);
    }
    update(kIcon | kTip);
    std::lock_guard<std::mutex> guard(lock);
    return lastError.empty() ? 1 : 0;
}

RKM_API void rkm_tray_tooltip(const char *text, size_t text_len)
{
    {
        std::lock_guard<std::mutex> guard(lock);
        tooltip = wide(text, text_len);
    }
    update(kTip);
}

RKM_API void rkm_tray_menu(const char *items, size_t items_len)
{
    std::lock_guard<std::mutex> guard(lock); // the menu is built when it opens
    menu.clear();
    std::istringstream in(std::string(items, items_len));
    std::string item;
    while (std::getline(in, item, '\n')) {
        if (!item.empty()) {
            menu.push_back(wide(item.data(), item.size()));
        }
    }
}

RKM_API void rkm_tray_remove(void)
{
    shutdown();
}

RKM_API const char *rkm_tray_error(void)
{
    std::lock_guard<std::mutex> guard(lock);
    return answer(lastError);
}

} // extern "C"

#endif // _WIN32
