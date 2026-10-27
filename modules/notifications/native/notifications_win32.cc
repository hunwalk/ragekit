// The notifications module's library on Windows: toast notifications (Windows.UI.Notifications,
// through C++/WinRT from the Windows SDK). An app that isn't installed from a package needs an
// AppUserModelID Windows knows: the first show() registers "RageKit.<exe name>" under
// HKCU\Software\Classes\AppUserModelId (its display name, and icon.png next to the executable when
// there is one), which is all Windows asks for; no Start menu shortcut. A click posts "clicked" and
// "closed"; dismissing it, or the app closing it, posts "closed". One that times out moves to the
// notification center and can still be clicked there while the app runs, so nothing is posted then.
#ifdef _WIN32

#include "rk_module_notifications.h"

#define NOMINMAX
#include <windows.h>
#include <shobjidl.h>

#include <winrt/Windows.Foundation.h>
#include <winrt/Windows.Foundation.Collections.h>
#include <winrt/Windows.Data.Xml.Dom.h>
#include <winrt/Windows.UI.Notifications.h>

#include <algorithm>
#include <cmath>
#include <cstdint>
#include <map>
#include <mutex>
#include <string>

namespace {

using namespace winrt::Windows::UI::Notifications;
using winrt::Windows::Data::Xml::Dom::XmlDocument;

rk_post_fn post = nullptr;

struct Shown {
    ToastNotification toast{nullptr};
    winrt::event_revoker<IToastNotification> activated, dismissed, failed;
};

std::mutex lock; // guards everything below
std::map<int64_t, Shown> ours; // ids this app showed and that aren't closed yet
int64_t nextId = 1;
std::string lastError;
std::wstring appId; // registered once
thread_local std::string result;

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

std::string narrow(const winrt::hstring &text)
{
    return winrt::to_string(text);
}

std::string failure(const winrt::hresult_error &error)
{
    char code[16];
    snprintf(code, sizeof code, "0x%08X", static_cast<unsigned>(error.code()));
    return narrow(error.message()) + " (" + code + ")";
}

void setString(HKEY key, const wchar_t *name, const std::wstring &value)
{
    RegSetValueExW(key, name, 0, REG_SZ, reinterpret_cast<const BYTE *>(value.c_str()),
                   static_cast<DWORD>((value.size() + 1) * sizeof(wchar_t)));
}

/** "RageKit.<exe name>", registered with Windows; under lock. '' when it can't be. */
std::wstring registeredId(std::string &why)
{
    if (!appId.empty()) {
        return appId;
    }
    // Toasts need COM; Slint's thread is already apartment-threaded, which suits them as well.
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);
    wchar_t path[MAX_PATH];
    DWORD len = GetModuleFileNameW(nullptr, path, MAX_PATH);
    std::wstring exe(path, len);
    size_t slash = exe.find_last_of(L"\\/");
    std::wstring dir = exe.substr(0, slash);
    std::wstring name = exe.substr(slash + 1);
    if (name.size() > 4 && _wcsicmp(name.c_str() + name.size() - 4, L".exe") == 0) {
        name.resize(name.size() - 4);
    }
    std::wstring id = L"RageKit." + name;
    for (wchar_t &c : id) {
        if (c == L' ' || c == L'\\') {
            c = L'_'; // an AUMID has no spaces
        }
    }
    HKEY key = nullptr;
    LSTATUS status = RegCreateKeyExW(HKEY_CURRENT_USER, (L"Software\\Classes\\AppUserModelId\\" + id).c_str(), 0, nullptr, 0,
                                     KEY_WRITE, nullptr, &key, nullptr);
    if (status != ERROR_SUCCESS) {
        why = "couldn't register the app with Windows (registry error " + std::to_string(status) + ")";
        return L"";
    }
    setString(key, L"DisplayName", name);
    std::wstring icon = dir + L"\\icon.png";
    if (GetFileAttributesW(icon.c_str()) != INVALID_FILE_ATTRIBUTES) {
        setString(key, L"IconUri", icon);
    } else {
        RegDeleteValueW(key, L"IconUri");
    }
    RegCloseKey(key);
    appId = id;
    return appId;
}

void notify(int64_t id, const char *kind)
{
    if (post != nullptr) {
        post("notifications", id, kind, "", 0);
    }
}

/** Forgets one (it stays forgotten: later events for it are ignored) and says it closed. */
void closed(int64_t id)
{
    {
        std::lock_guard<std::mutex> guard(lock);
        if (ours.erase(id) == 0) {
            return;
        }
    }
    notify(id, "closed");
}

void shutdown()
{
    std::map<int64_t, Shown> old;
    {
        std::lock_guard<std::mutex> guard(lock);
        old.swap(ours); // the revokers unhook the handlers as they go
    }
}

/** The app's main window: its visible top-level window without an owner (the taskbar button's). */
HWND mainWindow()
{
    HWND found = nullptr;
    EnumWindows([](HWND hwnd, LPARAM out) -> BOOL {
        DWORD pid = 0;
        GetWindowThreadProcessId(hwnd, &pid);
        if (pid == GetCurrentProcessId() && IsWindowVisible(hwnd) && GetWindow(hwnd, GW_OWNER) == nullptr) {
            *reinterpret_cast<HWND *>(out) = hwnd;
            return FALSE;
        }
        return TRUE;
    }, reinterpret_cast<LPARAM>(&found));
    return found;
}

/** A 16×16 red disc with the count in white (99+ past 99), for the taskbar button's overlay. */
HICON badgeIcon(int64_t count)
{
    const int size = 32; // drawn at twice the overlay's size for smooth edges; Windows scales it
    BITMAPV5HEADER header{};
    header.bV5Size = sizeof header;
    header.bV5Width = size;
    header.bV5Height = -size;
    header.bV5Planes = 1;
    header.bV5BitCount = 32;
    header.bV5Compression = BI_BITFIELDS;
    header.bV5RedMask = 0x00FF0000;
    header.bV5GreenMask = 0x0000FF00;
    header.bV5BlueMask = 0x000000FF;
    header.bV5AlphaMask = 0xFF000000;
    void *bits = nullptr;
    HDC screen = GetDC(nullptr);
    HBITMAP color = CreateDIBSection(screen, reinterpret_cast<BITMAPINFO *>(&header), DIB_RGB_COLORS, &bits, nullptr, 0);
    ReleaseDC(nullptr, screen);
    if (color == nullptr) {
        return nullptr;
    }
    HDC dc = CreateCompatibleDC(nullptr);
    HGDIOBJ old = SelectObject(dc, color);
    auto *pixels = static_cast<uint32_t *>(bits);
    std::fill(pixels, pixels + size * size, 0u);
    // The disc, with its alpha (GDI doesn't write alpha): drawn by hand.
    for (int y = 0; y < size; y++) {
        for (int x = 0; x < size; x++) {
            double dx = x + 0.5 - size / 2.0, dy = y + 0.5 - size / 2.0;
            double inside = size / 2.0 - std::sqrt(dx * dx + dy * dy);
            if (inside > 0) {
                uint32_t a = inside >= 1 ? 255u : static_cast<uint32_t>(inside * 255);
                pixels[y * size + x] = (a << 24) | (((0xDC * a) / 255) << 16) | (((0x26 * a) / 255) << 8) | ((0x26 * a) / 255);
            }
        }
    }
    std::wstring label = count > 99 ? L"99+" : std::to_wstring(count);
    HFONT font = CreateFontW(label.size() > 2 ? -13 : label.size() > 1 ? -17 : -22, 0, 0, 0, FW_BOLD, 0, 0, 0, DEFAULT_CHARSET, 0, 0,
                             ANTIALIASED_QUALITY, 0, L"Segoe UI");
    HGDIOBJ oldFont = SelectObject(dc, font);
    SetBkMode(dc, TRANSPARENT);
    SetTextColor(dc, RGB(255, 255, 255));
    RECT all{0, 0, size, size};
    DrawTextW(dc, label.c_str(), static_cast<int>(label.size()), &all, DT_CENTER | DT_VCENTER | DT_SINGLELINE);
    GdiFlush();
    // GDI writes text with alpha 0: inside the disc, those pixels become opaque again.
    for (int y = 0; y < size; y++) {
        for (int x = 0; x < size; x++) {
            double dx = x + 0.5 - size / 2.0, dy = y + 0.5 - size / 2.0;
            uint32_t &p = pixels[y * size + x];
            if (size / 2.0 - std::sqrt(dx * dx + dy * dy) >= 1 && (p >> 24) == 0) {
                p |= 0xFF000000u;
            }
        }
    }
    SelectObject(dc, oldFont);
    DeleteObject(font);
    SelectObject(dc, old);
    DeleteDC(dc);
    HBITMAP mask = CreateBitmap(size, size, 1, 1, nullptr);
    ICONINFO info{TRUE, 0, 0, mask, color};
    HICON icon = CreateIconIndirect(&info);
    DeleteObject(mask);
    DeleteObject(color);
    return icon;
}

/** The count on the taskbar button (an overlay icon; 0 removes it). */
bool badge(int64_t count, std::string &why)
{
    HWND window = mainWindow();
    if (window == nullptr) {
        why = "no window to badge yet";
        return false;
    }
    struct Com {
        HRESULT hr = CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);
        ~Com()
        {
            if (SUCCEEDED(hr)) {
                CoUninitialize(); // each successful init needs its own uninit
            }
        }
    } com;
    ITaskbarList3 *taskbar = nullptr;
    if (FAILED(CoCreateInstance(CLSID_TaskbarList, nullptr, CLSCTX_INPROC_SERVER, IID_PPV_ARGS(&taskbar))) || FAILED(taskbar->HrInit())) {
        if (taskbar != nullptr) {
            taskbar->Release();
        }
        why = "the taskbar isn't available";
        return false;
    }
    HICON icon = count > 0 ? badgeIcon(count) : nullptr;
    std::wstring text = count > 0 ? std::to_wstring(count) + L" unread" : L"";
    HRESULT hr = taskbar->SetOverlayIcon(window, icon, text.c_str());
    if (icon != nullptr) {
        DestroyIcon(icon);
    }
    taskbar->Release();
    if (FAILED(hr)) {
        why = "Windows didn't take the badge";
        return false;
    }
    return true;
}

struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); }
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_notifications_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_notifications_reset(void)
{
    shutdown();
}

RKM_API const char *rkm_notifications_available(void)
{
    std::lock_guard<std::mutex> guard(lock);
    std::string why;
    std::wstring id = registeredId(why);
    if (id.empty()) {
        return answer(why);
    }
    try {
        NotificationSetting setting = ToastNotificationManager::CreateToastNotifier(id).Setting();
        switch (setting) {
        case NotificationSetting::Enabled:
            return answer("");
        case NotificationSetting::DisabledForApplication:
            return answer("notifications are turned off for this app (Settings, System, Notifications)");
        case NotificationSetting::DisabledForUser:
            return answer("notifications are turned off (Settings, System, Notifications)");
        case NotificationSetting::DisabledByGroupPolicy:
            return answer("notifications are turned off by group policy");
        default:
            return answer("notifications are turned off on this computer");
        }
    } catch (const winrt::hresult_error &error) {
        if (error.code() == HRESULT_FROM_WIN32(ERROR_NOT_FOUND)) {
            return answer(""); // the setting is only readable for installed apps; showing works
        }
        return answer("notifications aren't available: " + failure(error));
    }
}

RKM_API int64_t rkm_notifications_show(const char *title, size_t title_len, const char *body, size_t body_len, const char *reply,
                                       size_t reply_len)
{
    std::wstring app;
    int64_t id;
    ToastNotification toast{nullptr};
    {
        std::lock_guard<std::mutex> guard(lock);
        lastError.clear();
        app = registeredId(lastError);
        if (app.empty()) {
            return 0;
        }
        id = nextId++;
        try {
            // Text nodes, so the title and body are never read as XML.
            XmlDocument doc;
            doc.LoadXml(L"<toast><visual><binding template=\"ToastGeneric\"><text/><text/></binding></visual></toast>");
            auto texts = doc.GetElementsByTagName(L"text");
            texts.Item(0).AppendChild(doc.CreateTextNode(wide(title, title_len)));
            std::wstring text = wide(body, body_len);
            if (!text.empty()) {
                texts.Item(1).AppendChild(doc.CreateTextNode(text));
            }
            std::wstring placeholder = wide(reply, reply_len);
            if (!placeholder.empty()) {
                // A reply field and its Send button; the text comes with Activated (UserInput).
                auto actions = doc.CreateElement(L"actions");
                auto input = doc.CreateElement(L"input");
                input.SetAttribute(L"id", L"reply");
                input.SetAttribute(L"type", L"text");
                input.SetAttribute(L"placeHolderContent", placeholder);
                auto send = doc.CreateElement(L"action");
                send.SetAttribute(L"content", L"Send");
                send.SetAttribute(L"arguments", L"reply");
                send.SetAttribute(L"hint-inputId", L"reply");
                actions.AppendChild(input);
                actions.AppendChild(send);
                doc.DocumentElement().AppendChild(actions);
            }
            Shown shown;
            shown.toast = toast = ToastNotification(doc);
            toast.Tag(winrt::to_hstring(id));
            // The handlers run on a Windows thread pool thread; post() is made for that.
            shown.activated = toast.Activated(winrt::auto_revoke, [id](auto &&, const winrt::Windows::Foundation::IInspectable &args) {
                auto activated = args.try_as<ToastActivatedEventArgs>();
                if (activated && activated.Arguments() == L"reply") {
                    winrt::hstring text;
                    if (auto value = activated.UserInput().TryLookup(L"reply")) {
                        text = winrt::unbox_value_or<winrt::hstring>(value, L"");
                    }
                    std::string utf8 = narrow(text);
                    if (post != nullptr) {
                        post("notifications", id, "replied", utf8.c_str(), utf8.size());
                    }
                } else {
                    notify(id, "clicked");
                }
                closed(id);
            });
            shown.dismissed = toast.Dismissed(winrt::auto_revoke, [id](auto &&, const ToastDismissedEventArgs &args) {
                if (args.Reason() != ToastDismissalReason::TimedOut) {
                    closed(id);
                }
            });
            shown.failed = toast.Failed(winrt::auto_revoke, [id](auto &&, auto &&) { closed(id); });
            ours.emplace(id, std::move(shown));
        } catch (const winrt::hresult_error &error) {
            lastError = "Windows didn't take it: " + failure(error);
            return 0;
        }
    }
    try {
        ToastNotificationManager::CreateToastNotifier(app).Show(toast); // outside the lock: a handler takes it
    } catch (const winrt::hresult_error &error) {
        std::lock_guard<std::mutex> guard(lock);
        ours.erase(id);
        lastError = "Windows didn't show it: " + failure(error);
        return 0;
    }
    return id;
}

RKM_API void rkm_notifications_close(int64_t notification)
{
    std::wstring app;
    ToastNotification toast{nullptr};
    {
        std::lock_guard<std::mutex> guard(lock);
        auto it = ours.find(notification);
        if (it == ours.end() || appId.empty()) {
            return;
        }
        app = appId;
        toast = it->second.toast;
    }
    try {
        ToastNotificationManager::CreateToastNotifier(app).Hide(toast); // still on screen
        ToastNotificationManager::History().Remove(winrt::to_hstring(notification), L"", app); // in the notification center
    } catch (const winrt::hresult_error &) {
        // already gone
    }
    closed(notification);
}

RKM_API const char *rkm_notifications_error(void)
{
    std::lock_guard<std::mutex> guard(lock);
    return answer(lastError);
}

RKM_API const char *rkm_notifications_badge(int64_t count)
{
    std::string why;
    return answer(badge(count, why) ? "" : why);
}

} // extern "C"

#endif // _WIN32
