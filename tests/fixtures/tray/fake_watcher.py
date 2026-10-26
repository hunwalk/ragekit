"""A fake org.kde.StatusNotifierWatcher for the tray module's tests: run it inside a throwaway D-Bus
session. It records each RegisterStatusNotifierItem call as "register <service> <sender>" in the log
file (argv[1]); the test then talks to the registered item itself with gdbus (properties, Activate,
the com.canonical.dbusmenu menu), as a tray host such as GNOME's AppIndicator extension would.
"""
import sys
from gi.repository import Gio, GLib

LOG = sys.argv[1]
XML = """
<node>
  <interface name="org.kde.StatusNotifierWatcher">
    <method name="RegisterStatusNotifierItem"><arg type="s" direction="in"/></method>
    <method name="RegisterStatusNotifierHost"><arg type="s" direction="in"/></method>
    <property name="RegisteredStatusNotifierItems" type="as" access="read"/>
    <property name="IsStatusNotifierHostRegistered" type="b" access="read"/>
    <property name="ProtocolVersion" type="i" access="read"/>
    <signal name="StatusNotifierItemRegistered"><arg type="s"/></signal>
  </interface>
</node>
"""
items = []


def log(line):
    with open(LOG, "a") as f:
        f.write(line + "\n")


def call(conn, sender, path, iface, method, params, invocation):
    if method == "RegisterStatusNotifierItem":
        (service,) = params.unpack()
        items.append(service)
        log("register %s %s" % (service, sender))
        invocation.return_value(None)
        conn.emit_signal(None, "/StatusNotifierWatcher", "org.kde.StatusNotifierWatcher", "StatusNotifierItemRegistered",
                         GLib.Variant("(s)", (service,)))
    else:
        invocation.return_value(None)


def prop(conn, sender, path, iface, name):
    if name == "RegisteredStatusNotifierItems":
        return GLib.Variant("as", items)
    if name == "IsStatusNotifierHostRegistered":
        return GLib.Variant("b", True)
    return GLib.Variant("i", 0)


def acquired(conn, name):
    node = Gio.DBusNodeInfo.new_for_xml(XML)
    conn.register_object("/StatusNotifierWatcher", node.interfaces[0], call, prop, None)
    log("ready")


Gio.bus_own_name(Gio.BusType.SESSION, "org.kde.StatusNotifierWatcher", Gio.BusNameOwnerFlags.NONE, acquired, None, None)
GLib.MainLoop().run()
