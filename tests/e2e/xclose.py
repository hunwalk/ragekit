"""Sends WM_DELETE_WINDOW (the close button) to a window: python3 -I xclose.py 0x200003"""
import ctypes, sys
x11 = ctypes.CDLL("libX11.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XInternAtom.restype = ctypes.c_ulong
class ClientMessage(ctypes.Structure):
    _fields_ = [("type", ctypes.c_int), ("serial", ctypes.c_ulong), ("send_event", ctypes.c_int),
                ("display", ctypes.c_void_p), ("window", ctypes.c_ulong), ("message_type", ctypes.c_ulong),
                ("format", ctypes.c_int), ("l", ctypes.c_long * 5), ("pad", ctypes.c_char * 96)]
d = ctypes.c_void_p(x11.XOpenDisplay(None))
w = int(sys.argv[1], 16)
ev = ClientMessage(type=33, window=w, message_type=x11.XInternAtom(d, b"WM_PROTOCOLS", 0), format=32)
ev.l[0] = x11.XInternAtom(d, b"WM_DELETE_WINDOW", 0)
x11.XSendEvent(d, ctypes.c_ulong(w), 0, 0, ctypes.byref(ev)); x11.XFlush(d)
