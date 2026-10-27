// rk::clip on Linux: the CLIPBOARD selection over a connection of its own to the X server (libX11,
// loaded at runtime; on GNOME/KDE Wayland that's XWayland, whose clipboard the desktop keeps in
// sync). It asks the owner for TARGETS, then for the best image type, and reads the answer,
// including large ones sent in pieces (INCR). A copied file (text/uri-list) counts when it's one
// image file. Without an X server (DISPLAY unset), wl-paste is used when it's installed.
#if !defined(_WIN32) && !defined(__APPLE__)

#include "clipboard.h"

#include <dlfcn.h>
#include <poll.h>
#include <sys/wait.h>
#include <unistd.h>

#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <iterator>
#include <string>
#include <vector>

namespace {

using Display = struct _XDisplay;
using XWindow = unsigned long;
using Atom = unsigned long;
using Bool = int;

constexpr int kSelectionNotify = 31;
constexpr int kPropertyNotify = 28;
constexpr long kPropertyChangeMask = 1L << 22;
constexpr int kPropertyNewValue = 0;
constexpr size_t kMaxBytes = 64u << 20; // a pasted image or file list larger than 64 MB is refused

struct SelectionEvent {
    int type;
    unsigned long serial;
    Bool send_event;
    Display *display;
    XWindow requestor;
    Atom selection;
    Atom target;
    Atom property;
    unsigned long time;
};

struct PropertyEvent {
    int type;
    unsigned long serial;
    Bool send_event;
    Display *display;
    XWindow window;
    Atom atom;
    unsigned long time;
    int state;
};

union Event {
    int type;
    SelectionEvent selection;
    PropertyEvent property;
    long pad[24]; // XEvent's size
};

struct Xlib {
    bool ok = false;
    Display *(*open)(const char *) = nullptr;
    int (*close)(Display *) = nullptr;
    XWindow (*root)(Display *) = nullptr;
    XWindow (*createWindow)(Display *, XWindow, int, int, unsigned, unsigned, unsigned, unsigned long, unsigned long) = nullptr;
    int (*destroyWindow)(Display *, XWindow) = nullptr;
    Atom (*internAtom)(Display *, const char *, Bool) = nullptr;
    XWindow (*selectionOwner)(Display *, Atom) = nullptr;
    int (*convertSelection)(Display *, Atom, Atom, Atom, XWindow, unsigned long) = nullptr;
    int (*selectInput)(Display *, XWindow, long) = nullptr;
    int (*flush)(Display *) = nullptr;
    int (*pending)(Display *) = nullptr;
    int (*nextEvent)(Display *, Event *) = nullptr;
    int (*connectionNumber)(Display *) = nullptr;
    int (*getProperty)(Display *, XWindow, Atom, long, long, Bool, Atom, Atom *, int *, unsigned long *, unsigned long *,
                       unsigned char **) = nullptr;
    int (*deleteProperty)(Display *, XWindow, Atom) = nullptr;
    int (*free)(void *) = nullptr;
    char *(*atomName)(Display *, Atom) = nullptr;
};

template <typename T>
bool find(void *handle, const char *name, T &out)
{
    out = reinterpret_cast<T>(dlsym(handle, name));
    return out != nullptr;
}

Xlib &xlib()
{
    static Xlib x = [] {
        Xlib l;
        void *h = dlopen("libX11.so.6", RTLD_NOW | RTLD_LOCAL);
        if (h == nullptr) {
            return l;
        }
        l.ok = find(h, "XOpenDisplay", l.open) && find(h, "XCloseDisplay", l.close) && find(h, "XDefaultRootWindow", l.root)
            && find(h, "XCreateSimpleWindow", l.createWindow) && find(h, "XDestroyWindow", l.destroyWindow)
            && find(h, "XInternAtom", l.internAtom) && find(h, "XGetSelectionOwner", l.selectionOwner)
            && find(h, "XConvertSelection", l.convertSelection) && find(h, "XSelectInput", l.selectInput)
            && find(h, "XFlush", l.flush) && find(h, "XPending", l.pending) && find(h, "XNextEvent", l.nextEvent)
            && find(h, "XConnectionNumber", l.connectionNumber) && find(h, "XGetWindowProperty", l.getProperty)
            && find(h, "XDeleteProperty", l.deleteProperty) && find(h, "XFree", l.free) && find(h, "XGetAtomName", l.atomName);
        return l;
    }();
    return x;
}

// Image types in the order they're preferred, with the extension the file gets.
const char *const kTypes[][2] = {
    {"image/png", "png"}, {"image/jpeg", "jpg"}, {"image/gif", "gif"}, {"image/webp", "webp"}, {"image/bmp", "bmp"},
};

const char *const kImageExtensions[] = {"png", "jpg", "jpeg", "gif", "webp", "bmp"};

using Clock = std::chrono::steady_clock;

/** One connection with a small window of its own to receive the selection on. */
class Session {
public:
    Session() : x_(xlib())
    {
        const char *display = getenv("DISPLAY");
        if (!x_.ok || display == nullptr || *display == '\0') {
            return;
        }
        d_ = x_.open(nullptr);
        if (d_ == nullptr) {
            return;
        }
        w_ = x_.createWindow(d_, x_.root(d_), 0, 0, 1, 1, 0, 0, 0);
        x_.selectInput(d_, w_, kPropertyChangeMask);
        clipboard_ = atom("CLIPBOARD");
        property_ = atom("RAGEKIT_CLIPBOARD");
        incr_ = atom("INCR");
    }
    ~Session()
    {
        if (d_ != nullptr) {
            x_.destroyWindow(d_, w_);
            x_.close(d_);
        }
    }
    bool open() const { return d_ != nullptr; }
    Atom atom(const char *name) { return x_.internAtom(d_, name, 0); }
    bool owned() { return x_.selectionOwner(d_, clipboard_) != 0; }

    /** The clipboard's content in `target`'s form; false if the owner refused or didn't answer in time. */
    bool read(Atom target, std::string &out, Atom &type, int &format, std::chrono::milliseconds timeout)
    {
        x_.deleteProperty(d_, w_, property_);
        x_.convertSelection(d_, clipboard_, target, property_, w_, 0 /* CurrentTime */);
        x_.flush(d_);
        auto deadline = Clock::now() + timeout;
        Event e;
        while (next(e, deadline)) {
            if (e.type != kSelectionNotify || e.selection.requestor != w_) {
                continue;
            }
            if (e.selection.property == 0) {
                return false; // the owner can't give it in that form
            }
            take(out, type, format);
            if (type != incr_) {
                return true;
            }
            // INCR: the owner sends it in pieces, each a new value of the property we delete.
            out.clear();
            x_.deleteProperty(d_, w_, property_);
            x_.flush(d_);
            while (next(e, deadline)) {
                if (e.type != kPropertyNotify || e.property.window != w_ || e.property.atom != property_
                    || e.property.state != kPropertyNewValue) {
                    continue;
                }
                std::string piece;
                take(piece, type, format);
                x_.flush(d_);
                if (piece.empty()) {
                    return true;
                }
                out += piece;
                if (out.size() > kMaxBytes) {
                    return false; // an owner that never stops sending
                }
                deadline = Clock::now() + timeout; // a large image: the time limit is per piece
            }
            return false;
        }
        return false;
    }

    std::string name(Atom a)
    {
        char *text = x_.atomName(d_, a);
        std::string out = text != nullptr ? text : "";
        if (text != nullptr) {
            x_.free(text);
        }
        return out;
    }

private:
    /** The next event, waiting until the deadline. */
    bool next(Event &e, Clock::time_point deadline)
    {
        while (x_.pending(d_) == 0) {
            auto left = std::chrono::duration_cast<std::chrono::milliseconds>(deadline - Clock::now()).count();
            if (left <= 0) {
                return false;
            }
            pollfd fd{x_.connectionNumber(d_), POLLIN, 0};
            poll(&fd, 1, static_cast<int>(left));
        }
        x_.nextEvent(d_, &e);
        return true;
    }

    /** Reads the whole property and deletes it. Format 32 items are longs in memory (Xlib's rule). */
    void take(std::string &out, Atom &type, int &format)
    {
        out.clear();
        long offset = 0;
        while (true) {
            unsigned long items = 0, after = 0;
            unsigned char *data = nullptr;
            if (x_.getProperty(d_, w_, property_, offset, 1 << 20, 0, 0 /* AnyPropertyType */, &type, &format, &items, &after,
                               &data) != 0) {
                break;
            }
            size_t unit = format == 32 ? sizeof(long) : static_cast<size_t>(format / 8);
            if (data != nullptr) {
                out.append(reinterpret_cast<char *>(data), items * unit);
                x_.free(data);
            }
            offset += static_cast<long>(items * static_cast<unsigned long>(format) / 32);
            if (after == 0) {
                break;
            }
        }
        x_.deleteProperty(d_, w_, property_);
    }

    Xlib &x_;
    Display *d_ = nullptr;
    XWindow w_ = 0;
    Atom clipboard_ = 0, property_ = 0, incr_ = 0;
};

/** The owner's TARGETS as names. */
std::vector<std::string> targets(Session &s)
{
    std::vector<std::string> names;
    std::string data;
    Atom type = 0;
    int format = 0;
    if (!s.owned() || !s.read(s.atom("TARGETS"), data, type, format, std::chrono::milliseconds(500)) || format != 32) {
        return names;
    }
    const long *atoms = reinterpret_cast<const long *>(data.data());
    for (size_t i = 0; i < data.size() / sizeof(long); i++) {
        names.push_back(s.name(static_cast<Atom>(atoms[i])));
    }
    return names;
}

bool has(const std::vector<std::string> &list, const std::string &name)
{
    for (const std::string &item : list) {
        if (item == name) {
            return true;
        }
    }
    return false;
}

std::string lower(std::string text)
{
    for (char &c : text) {
        c = static_cast<char>(c >= 'A' && c <= 'Z' ? c + 32 : c);
    }
    return text;
}

/** A text/uri-list with exactly one local image file: its path; else ''. */
std::string image_file(const std::string &uris)
{
    std::vector<std::string> files;
    size_t start = 0;
    while (start < uris.size()) {
        size_t end = uris.find('\n', start);
        std::string line = uris.substr(start, end == std::string::npos ? std::string::npos : end - start);
        start = end == std::string::npos ? uris.size() : end + 1;
        while (!line.empty() && (line.back() == '\r' || line.back() == '\0')) {
            line.pop_back();
        }
        if (line.empty() || line[0] == '#') {
            continue;
        }
        if (line.rfind("file://", 0) != 0) {
            return "";
        }
        std::string path;
        size_t from = line.find('/', 7); // file://host/path: skip an optional host
        for (size_t i = from == std::string::npos ? line.size() : from; i < line.size(); i++) {
            if (line[i] == '%' && i + 2 < line.size()) {
                path += static_cast<char>(std::strtol(line.substr(i + 1, 2).c_str(), nullptr, 16));
                i += 2;
            } else {
                path += line[i];
            }
        }
        files.push_back(path);
    }
    if (files.size() != 1) {
        return "";
    }
    std::string ext = lower(files[0].substr(files[0].find_last_of('.') + 1));
    for (const char *known : kImageExtensions) {
        if (ext == known) {
            return files[0];
        }
    }
    return "";
}

bool read_file(const std::string &path, std::string &bytes, std::string &extension)
{
    std::ifstream in(path, std::ios::binary);
    if (!in) {
        return false;
    }
    bytes.assign(std::istreambuf_iterator<char>(in), std::istreambuf_iterator<char>());
    extension = lower(path.substr(path.find_last_of('.') + 1));
    if (extension == "jpeg") {
        extension = "jpg";
    }
    return !bytes.empty();
}

/** Runs wl-paste with these arguments; its output, or false if it failed or isn't installed. */
bool wl_paste(const std::vector<std::string> &args, std::string &out)
{
    int pipefd[2];
    if (pipe(pipefd) != 0) {
        return false;
    }
    pid_t pid = fork();
    if (pid < 0) {
        close(pipefd[0]);
        close(pipefd[1]);
        return false;
    }
    if (pid == 0) {
        dup2(pipefd[1], 1);
        close(pipefd[0]);
        close(pipefd[1]);
        std::vector<char *> argv{const_cast<char *>("wl-paste")};
        for (const std::string &a : args) {
            argv.push_back(const_cast<char *>(a.c_str()));
        }
        argv.push_back(nullptr);
        execvp("wl-paste", argv.data());
        _exit(127);
    }
    close(pipefd[1]);
    out.clear();
    char buffer[65536];
    ssize_t n;
    while ((n = read(pipefd[0], buffer, sizeof buffer)) > 0) {
        out.append(buffer, static_cast<size_t>(n));
    }
    close(pipefd[0]);
    int status = 0;
    waitpid(pid, &status, 0);
    return WIFEXITED(status) && WEXITSTATUS(status) == 0;
}

bool wayland_only()
{
    const char *display = getenv("DISPLAY");
    const char *wayland = getenv("WAYLAND_DISPLAY");
    return (display == nullptr || *display == '\0') && wayland != nullptr && *wayland != '\0';
}

/** What to ask for: an image type, or text/uri-list for a copied file; '' when neither. */
std::string pick(const std::vector<std::string> &offered, std::string &extension)
{
    for (const auto &type : kTypes) {
        if (has(offered, type[0])) {
            extension = type[1];
            return type[0];
        }
    }
    return has(offered, "text/uri-list") ? "text/uri-list" : "";
}

std::vector<std::string> wayland_types()
{
    std::vector<std::string> list;
    std::string out;
    if (!wl_paste({"--no-newline", "--list-types"}, out)) {
        return list;
    }
    size_t start = 0;
    while (start < out.size()) {
        size_t end = out.find('\n', start);
        list.push_back(out.substr(start, end == std::string::npos ? std::string::npos : end - start));
        start = end == std::string::npos ? out.size() : end + 1;
    }
    return list;
}

} // namespace

namespace rk::clip {

bool has_image()
{
    std::string bytes, extension;
    if (wayland_only()) {
        std::vector<std::string> offered = wayland_types();
        std::string type = pick(offered, extension);
        if (type == "text/uri-list") {
            return wl_paste({"--no-newline", "--type", type}, bytes) && !image_file(bytes).empty();
        }
        return !type.empty();
    }
    Session s;
    if (!s.open()) {
        return false;
    }
    std::vector<std::string> offered = targets(s);
    std::string type = pick(offered, extension);
    if (type == "text/uri-list") {
        Atom got = 0;
        int format = 0;
        return s.read(s.atom(type.c_str()), bytes, got, format, std::chrono::milliseconds(500)) && !image_file(bytes).empty();
    }
    return !type.empty();
}

bool image(std::string &bytes, std::string &extension)
{
    bytes.clear();
    std::string type;
    if (wayland_only()) {
        type = pick(wayland_types(), extension);
        if (type.empty() || !wl_paste({"--no-newline", "--type", type}, bytes)) {
            return false;
        }
    } else {
        Session s;
        if (!s.open()) {
            return false;
        }
        type = pick(targets(s), extension);
        Atom got = 0;
        int format = 0;
        if (type.empty() || !s.read(s.atom(type.c_str()), bytes, got, format, std::chrono::milliseconds(3000))) {
            return false;
        }
    }
    if (type == "text/uri-list") {
        std::string file = image_file(bytes);
        return !file.empty() && read_file(file, bytes, extension);
    }
    return !bytes.empty();
}

} // namespace rk::clip

#endif
