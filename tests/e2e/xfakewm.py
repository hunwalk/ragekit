"""Pretends a window manager supports _NET_WM_MOVERESIZE (sets _NET_SUPPORTED on the root) without
running one, until killed: python3 -I xfakewm.py &. Apps then send the message and nobody takes the
drag. It stays connected because Xvfb resets (dropping root properties) when its last client leaves."""
import ctypes, signal, time
x11 = ctypes.CDLL("libX11.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XDefaultRootWindow.restype = ctypes.c_ulong
x11.XDefaultRootWindow.argtypes = [ctypes.c_void_p]
x11.XInternAtom.restype = ctypes.c_ulong
x11.XInternAtom.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_int]
x11.XChangeProperty.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_int, ctypes.c_int, ctypes.c_void_p, ctypes.c_int]
x11.XDeleteProperty.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_ulong]
d = ctypes.c_void_p(x11.XOpenDisplay(None))
root = x11.XDefaultRootWindow(d)
supported = x11.XInternAtom(d, b"_NET_SUPPORTED", 0)
atoms = (ctypes.c_ulong * 1)(x11.XInternAtom(d, b"_NET_WM_MOVERESIZE", 0))
x11.XChangeProperty(d, root, supported, 4, 32, 0, atoms, 1)  # XA_ATOM, PropModeReplace
x11.XFlush(d)


def stop(*_):
    x11.XDeleteProperty(d, root, supported)
    x11.XCloseDisplay(d)
    raise SystemExit(0)


signal.signal(signal.SIGTERM, stop)
while True:
    time.sleep(1)
