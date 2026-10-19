// rk::win on Windows: posts WM_NCLBUTTONDOWN as if the press were on the caption or a border, so
// Windows runs its own move/resize loop (with Aero Snap), the way winit's drag_window() does. DWM
// gives the frameless window its shadow and, on Windows 11, rounded corners.
#ifdef _WIN32

#include "window.h"
#include "double_click.h"

#include <windows.h>
#include <dwmapi.h>

#pragma comment(lib, "dwmapi.lib")
#pragma comment(lib, "user32.lib")

namespace {

// From the Windows 11 SDK (dwmapi.h); spelled out so older SDKs build too.
constexpr DWORD CornerPreference = 33; // DWMWA_WINDOW_CORNER_PREFERENCE
constexpr int CornerRound = 2;         // DWMWCP_ROUND

void post(slint::Window &window, WPARAM hit)
{
    HWND hwnd = window.win32_hwnd();
    if (hwnd == nullptr) {
        return;
    }
    POINT point;
    GetCursorPos(&point);
    ReleaseCapture();
    PostMessageW(hwnd, WM_NCLBUTTONDOWN, hit, MAKELPARAM(point.x, point.y));
}

} // namespace

namespace rk::win {

void prefer_x11()
{
}

void make_floating(slint::Window &window)
{
    HWND hwnd = window.win32_hwnd();
    if (hwnd == nullptr) {
        return;
    }
    int corner = CornerRound;
    DwmSetWindowAttribute(hwnd, CornerPreference, &corner, sizeof corner); // fails harmlessly before Windows 11
    MARGINS margins{1, 1, 1, 1};
    DwmExtendFrameIntoClientArea(hwnd, &margins); // keeps DWM's shadow on a window without a frame
}

bool double_click()
{
    static DoubleClick clicks;
    POINT point;
    if (!GetCursorPos(&point)) {
        return false;
    }
    return clicks.press(static_cast<long long>(GetTickCount64()), point.x, point.y, GetDoubleClickTime(), GetSystemMetrics(SM_CXDOUBLECLK) / 2);
}

void start_move(slint::Window &window)
{
    post(window, HTCAPTION);
}

void move(slint::Window &)
{
}

void start_resize(slint::Window &window, Edge edge)
{
    static constexpr WPARAM hits[] = {HTTOPLEFT, HTTOP, HTTOPRIGHT, HTRIGHT, HTBOTTOMRIGHT, HTBOTTOM, HTBOTTOMLEFT, HTLEFT};
    post(window, hits[static_cast<int>(edge)]);
}

} // namespace rk::win

#endif
