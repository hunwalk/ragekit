"""Moves the pointer to x,y screen positions via XTest (hover), pausing at each: python3 -I xmove.py 10,20 30,40"""
import ctypes, sys, time
x11 = ctypes.CDLL("libX11.so.6")
xt = ctypes.CDLL("libXtst.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
d = ctypes.c_void_p(x11.XOpenDisplay(None))
for pair in sys.argv[1:]:
    x, y = map(int, pair.split(","))
    xt.XTestFakeMotionEvent(d, -1, x, y, 0); x11.XFlush(d); time.sleep(0.3)
