// rk::win on Windows: posts WM_NCLBUTTONDOWN as if the press were on the caption or a border, so
// Windows runs its own move/resize loop (with Aero Snap), the way winit's drag_window() does. DWM
// gives the frameless window its shadow and, on Windows 11, rounded corners.
#ifdef _WIN32

#include "window.h"
#include "double_click.h"

#include <windows.h>
#include <dwmapi.h>

#include <unordered_map>
#include <unordered_set>

#pragma comment(lib, "dwmapi.lib")
#pragma comment(lib, "user32.lib")

namespace {

// From the Windows 11 SDK (dwmapi.h); spelled out so older SDKs build too.
constexpr DWORD CornerPreference = 33; // DWMWA_WINDOW_CORNER_PREFERENCE
constexpr int CornerRound = 2;         // DWMWCP_ROUND

// Windows' move/resize loop swallows the button release, so Slint would keep the drag area (or
// edge grip) pressed, and a pressed area gets every later click: each would start another drag.
// So Slint first hears that the button went up. On the next event-loop turn: this runs inside
// Slint's own handling of the press.
void post(slint::Window &window, WPARAM hit)
{
    HWND hwnd = window.win32_hwnd();
    if (hwnd == nullptr) {
        return;
    }
    POINT point;
    GetCursorPos(&point);
    POINT client = point;
    ScreenToClient(hwnd, &client);
    float scale = window.scale_factor();
    slint::LogicalPosition position({static_cast<float>(client.x) / scale, static_cast<float>(client.y) / scale});
    slint::Window *target = &window;
    slint::invoke_from_event_loop([target, hwnd, hit, point, position] {
        if (!IsWindow(hwnd)) {
            return; // closed meanwhile, and its slint::Window with it
        }
        target->dispatch_pointer_release_event(position, slint::PointerEventButton::Left);
        ReleaseCapture();
        PostMessageW(hwnd, WM_NCLBUTTONDOWN, hit, MAKELPARAM(point.x, point.y));
    });
}

// The windows skip_frames_while_minimised() watches, with the window procedure it replaced. UI thread only.
std::unordered_map<HWND, WNDPROC> watched;
std::unordered_map<HWND, slint::Window *> window_of;
std::unordered_set<HWND> was_minimised;

LRESULT CALLBACK watch_proc(HWND hwnd, UINT message, WPARAM wParam, LPARAM lParam)
{
    auto it = watched.find(hwnd);
    if (it == watched.end()) {
        return DefWindowProcW(hwnd, message, wParam, lParam);
    }
    WNDPROC previous = it->second;
    if (message == WM_NCDESTROY) {
        SetWindowLongPtrW(hwnd, GWLP_WNDPROC, reinterpret_cast<LONG_PTR>(previous));
        watched.erase(it);
        window_of.erase(hwnd);
        was_minimised.erase(hwnd);
    } else if (message == WM_PAINT && IsIconic(hwnd)) {
        // Slint's software renderer copies only what changed to the window, and a frame drawn
        // while minimised (a blinking caret asks for one every half second) left the window, once
        // restored, empty except for those parts. Minimised, nothing is drawn.
        PAINTSTRUCT paint;
        BeginPaint(hwnd, &paint);
        EndPaint(hwnd, &paint);
        return 0;
    }
    LRESULT result = CallWindowProcW(previous, hwnd, message, wParam, lParam);
    bool back = false;
    if (message == WM_SIZE && wParam != SIZE_MINIMIZED && wParam != SIZE_MAXHIDE) {
        back = was_minimised.erase(hwnd) != 0; // restored
    } else if (message == WM_SIZE) {
        was_minimised.insert(hwnd);
    } else if (message == WM_SHOWWINDOW && wParam == TRUE) {
        back = true; // shown again after a hide (the tray)
    }
    if (back) {
        // Restored or shown, the window has lost what was on it, but Slint's software renderer
        // redraws and copies to the window only the items that changed since its last frame
        // (winit never reports the window as occluded on Windows, which is what makes Slint
        // redraw everything elsewhere). A snapshot renders into a fresh buffer, which empties
        // the renderer's record of what's on screen, so the next frame draws every item.
        auto target = window_of.find(hwnd);
        if (target != window_of.end()) {
            slint::Window *window = target->second;
            slint::invoke_from_event_loop([window, hwnd] {
                if (!IsWindow(hwnd)) {
                    return;
                }
                window->take_snapshot();
                window->request_redraw();
            });
        }
    }
    return result;
}

// The first window gets its HWND only once Slint's event loop runs: until then, try again on each
// turn of the loop (a few dozen at most).
void watch(slint::Window *window, int tries)
{
    HWND hwnd = window->win32_hwnd();
    if (hwnd == nullptr) {
        if (tries > 0) {
            slint::invoke_from_event_loop([window, tries] { watch(window, tries - 1); });
        }
        return;
    }
    if (watched.count(hwnd) != 0) {
        return;
    }
    auto previous = reinterpret_cast<WNDPROC>(SetWindowLongPtrW(hwnd, GWLP_WNDPROC, reinterpret_cast<LONG_PTR>(watch_proc)));
    if (previous != nullptr) {
        watched[hwnd] = previous;
        window_of[hwnd] = window;
    }
}

} // namespace

namespace rk::win {

void skip_frames_while_minimised(slint::Window &window)
{
    watch(&window, 50);
}

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

void resize(slint::Window &)
{
}

void start_resize(slint::Window &window, Edge edge)
{
    static constexpr WPARAM hits[] = {HTTOPLEFT, HTTOP, HTTOPRIGHT, HTRIGHT, HTBOTTOMRIGHT, HTBOTTOM, HTBOTTOMLEFT, HTLEFT};
    post(window, hits[static_cast<int>(edge)]);
}

// Windows' foreground lock refuses this to an app in the background, unless the user's last input went
// to it, or a launch the user started allowed it (a second instance handing over a link does).
// Refused, Windows flashes the taskbar button instead.
void raise(slint::Window &window, const char *)
{
    window.set_minimized(false);
    HWND hwnd = window.win32_hwnd();
    if (hwnd == nullptr) {
        return;
    }
    if (IsIconic(hwnd)) {
        ShowWindow(hwnd, SW_RESTORE);
    }
    SetForegroundWindow(hwnd);
}

// The foreground right comes with the handover itself (AllowSetForegroundWindow): no token.
void set_activation_token(const std::string &)
{
}

std::string take_activation_token()
{
    return "";
}

bool activate_wayland(slint::Window &)
{
    return false;
}

} // namespace rk::win

#endif
