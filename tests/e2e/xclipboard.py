"""The X11 clipboard through GTK: python3 -I xclipboard.py set <text> <seconds> (owns the clipboard that
long, answering requests) | get (prints its text, or nothing)."""
import sys
import gi
gi.require_version("Gdk", "3.0")
gi.require_version("Gtk", "3.0")
from gi.repository import Gdk, GLib, Gtk

clipboard = Gtk.Clipboard.get(Gdk.SELECTION_CLIPBOARD)
if sys.argv[1] == "set":
    clipboard.set_text(sys.argv[2], -1)
    GLib.timeout_add(int(float(sys.argv[3]) * 1000), Gtk.main_quit)
    Gtk.main()
else:
    text = clipboard.wait_for_text()
    sys.stdout.write(text or "")
