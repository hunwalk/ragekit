"""Types ASCII letters/digits via XTest: python3 -I xtype.py Ann [0xWINDOWID]
Without a window manager nothing owns the input focus: pass the window id to focus it first."""
import ctypes, sys, time
x11 = ctypes.CDLL("libX11.so.6")
xt = ctypes.CDLL("libXtst.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XStringToKeysym.restype = ctypes.c_ulong
d = ctypes.c_void_p(x11.XOpenDisplay(None))
if len(sys.argv) > 2:
    x11.XSetInputFocus(d, ctypes.c_ulong(int(sys.argv[2], 16)), 1, 0); x11.XSync(d, 0)
shift = x11.XKeysymToKeycode(d, x11.XStringToKeysym(b"Shift_L"))
for ch in sys.argv[1]:
    code = x11.XKeysymToKeycode(d, ctypes.c_ulong(x11.XStringToKeysym(ch.lower().encode())))
    if ch.isupper(): xt.XTestFakeKeyEvent(d, shift, 1, 0)
    xt.XTestFakeKeyEvent(d, code, 1, 0); xt.XTestFakeKeyEvent(d, code, 0, 0)
    if ch.isupper(): xt.XTestFakeKeyEvent(d, shift, 0, 0)
    x11.XFlush(d); time.sleep(0.1)
