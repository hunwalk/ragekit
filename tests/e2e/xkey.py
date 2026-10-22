"""Presses a key by X keysym name via XTest, with modifiers joined by +:
python3 -I xkey.py Escape [0xWINDOWID], python3 -I xkey.py Alt_L+Left [0xWINDOWID]
Without a window manager nothing owns the input focus: pass the window id to focus it first."""
import ctypes, sys
x11 = ctypes.CDLL("libX11.so.6")
xt = ctypes.CDLL("libXtst.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XStringToKeysym.restype = ctypes.c_ulong
d = ctypes.c_void_p(x11.XOpenDisplay(None))
if len(sys.argv) > 2:
    x11.XSetInputFocus(d, ctypes.c_ulong(int(sys.argv[2], 16)), 1, 0); x11.XSync(d, 0)
codes = [x11.XKeysymToKeycode(d, ctypes.c_ulong(x11.XStringToKeysym(name.encode()))) for name in sys.argv[1].split("+")]
for code in codes:
    xt.XTestFakeKeyEvent(d, code, 1, 0)
for code in reversed(codes):
    xt.XTestFakeKeyEvent(d, code, 0, 0)
x11.XFlush(d)
