"""Types ASCII text via XTest (letters, digits, spaces and punctuation): python3 -I xtype.py "Ann /tmp/a.png" [0xWINDOWID]
Without a window manager nothing owns the input focus: pass the window id to focus it first."""
import ctypes, sys, time
x11 = ctypes.CDLL("libX11.so.6")
xt = ctypes.CDLL("libXtst.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XStringToKeysym.restype = ctypes.c_ulong
x11.XKeycodeToKeysym.restype = ctypes.c_ulong
d = ctypes.c_void_p(x11.XOpenDisplay(None))
if len(sys.argv) > 2:
    x11.XSetInputFocus(d, ctypes.c_ulong(int(sys.argv[2], 16)), 1, 0); x11.XSync(d, 0)
# Keysym names of characters that aren't their own name.
NAMES = {" ": "space", "/": "slash", ".": "period", "-": "minus", "_": "underscore", ":": "colon", ",": "comma",
         "?": "question", "!": "exclam", "'": "apostrophe", "@": "at", "#": "numbersign", "=": "equal", "+": "plus"}
shift = x11.XKeysymToKeycode(d, x11.XStringToKeysym(b"Shift_L"))
for ch in sys.argv[1]:
    keysym = x11.XStringToKeysym(NAMES.get(ch, ch).encode())
    code = x11.XKeysymToKeycode(d, ctypes.c_ulong(keysym))
    # Shift when the key's own (unshifted) symbol is another one: A, _, :, ? …
    shifted = x11.XKeycodeToKeysym(d, ctypes.c_ubyte(code), 0) != keysym
    if shifted: xt.XTestFakeKeyEvent(d, shift, 1, 0)
    xt.XTestFakeKeyEvent(d, code, 1, 0); xt.XTestFakeKeyEvent(d, code, 0, 0)
    if shifted: xt.XTestFakeKeyEvent(d, shift, 0, 0)
    x11.XFlush(d); time.sleep(0.05)
