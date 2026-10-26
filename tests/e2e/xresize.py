"""Resizes a window via Xlib (no window manager needed): python3 -I xresize.py 0x200003 480 260"""
import ctypes, sys, time
x11 = ctypes.CDLL("libX11.so.6"); x11.XOpenDisplay.restype = ctypes.c_void_p
d = ctypes.c_void_p(x11.XOpenDisplay(None))
x11.XResizeWindow(d, ctypes.c_ulong(int(sys.argv[1], 16)), int(sys.argv[2]), int(sys.argv[3])); x11.XFlush(d); time.sleep(0.5)
