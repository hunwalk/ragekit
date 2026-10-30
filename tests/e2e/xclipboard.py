"""The X11 clipboard through GTK: python3 -I xclipboard.py set <text> <seconds> (owns the clipboard that
long, answering requests) | image <file> <seconds> (an image, offered as png, jpeg, bmp, …, the way an
image editor or browser copies one) | file <path> <seconds> (a copied file: text/uri-list, as a file
manager copies one) | get (prints its text, or nothing) | targets (prints the formats it offers, one
per line, or nothing)."""
import sys
import gi
gi.require_version("Gdk", "3.0")
gi.require_version("Gtk", "3.0")
from gi.repository import Gdk, GdkPixbuf, GLib, Gtk

clipboard = Gtk.Clipboard.get(Gdk.SELECTION_CLIPBOARD)
if sys.argv[1] == "get":
    text = clipboard.wait_for_text()
    sys.stdout.write(text or "")
    sys.exit(0)
if sys.argv[1] == "targets":
    available, targets = clipboard.wait_for_targets()
    sys.stdout.write("".join(target.name() + "\n" for target in targets) if available else "")
    sys.exit(0)
if sys.argv[1] == "set":
    clipboard.set_text(sys.argv[2], -1)
elif sys.argv[1] == "image":
    clipboard.set_image(GdkPixbuf.Pixbuf.new_from_file(sys.argv[2]))
elif sys.argv[1] == "file":
    # PyGObject has no Clipboard.set_with_data: own the selection through an invisible widget.
    uri = GLib.filename_to_uri(sys.argv[2], None)
    uri_list = Gdk.Atom.intern("text/uri-list", False)
    owner = Gtk.Invisible()
    owner.connect("selection-get", lambda w, data, info, time: data.set(uri_list, 8, (uri + "\r\n").encode()))
    Gtk.selection_owner_set(owner, Gdk.SELECTION_CLIPBOARD, Gdk.CURRENT_TIME)
    Gtk.selection_add_target(owner, Gdk.SELECTION_CLIPBOARD, uri_list, 0)
GLib.timeout_add(int(float(sys.argv[3]) * 1000), Gtk.main_quit)
Gtk.main()
