"""Turns the mouse wheel at x,y via XTest: python3 -I xscroll.py 200,150 up 10 (or down N)"""
import ctypes, sys, time
x11 = ctypes.CDLL("libX11.so.6")
xt = ctypes.CDLL("libXtst.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
d = ctypes.c_void_p(x11.XOpenDisplay(None))
x, y = map(int, sys.argv[1].split(","))
button = 4 if sys.argv[2] == "up" else 5
xt.XTestFakeMotionEvent(d, -1, x, y, 0); x11.XFlush(d); time.sleep(0.15)
for _ in range(int(sys.argv[3])):
    xt.XTestFakeButtonEvent(d, button, 1, 0); x11.XFlush(d); time.sleep(0.02)
    xt.XTestFakeButtonEvent(d, button, 0, 0); x11.XFlush(d); time.sleep(0.05)
time.sleep(0.3)
