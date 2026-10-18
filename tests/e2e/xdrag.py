"""Drags with the left button via XTest: python3 -I xdrag.py x1,y1 x2,y2 (press, move in steps, release)"""
import ctypes, sys, time
x11 = ctypes.CDLL("libX11.so.6")
xt = ctypes.CDLL("libXtst.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
d = ctypes.c_void_p(x11.XOpenDisplay(None))
(x1, y1), (x2, y2) = (map(int, p.split(",")) for p in sys.argv[1:3])
xt.XTestFakeMotionEvent(d, -1, x1, y1, 0); x11.XFlush(d); time.sleep(0.15)
xt.XTestFakeButtonEvent(d, 1, 1, 0); x11.XFlush(d); time.sleep(0.08)
steps = 10
for i in range(1, steps + 1):
    xt.XTestFakeMotionEvent(d, -1, x1 + (x2 - x1) * i // steps, y1 + (y2 - y1) * i // steps, 0)
    x11.XFlush(d); time.sleep(0.03)
xt.XTestFakeButtonEvent(d, 1, 0, 0); x11.XFlush(d); time.sleep(0.3)
