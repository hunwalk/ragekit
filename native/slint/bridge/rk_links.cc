// App links (`devnull://…`) for apps that declare them (project.yml `ragekit: { links: [...] }`):
// one running instance per app, which later launches hand their link (or just "come to the front")
// to; and registering the app as the handler of a scheme, on request. Incoming links reach PHP as
// the module event "@links" / 0 / "open" (data: the URL) or "focus". Kept free of Slint.
//   Linux: a Unix socket in $XDG_RUNTIME_DIR (else /tmp, with the user id in the name), claimed
//   under a lock file; registration writes a .desktop file and the default in mimeapps.list.
//   Windows (untested): a named pipe, and HKCU\Software\Classes\<scheme>.
#ifndef RK_BUILDING_BRIDGE
#define RK_BUILDING_BRIDGE
#endif
#include "ragekit_slint.h"
#include "activation.h"

#include <algorithm>
#include <atomic>
#include <cctype>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <filesystem>
#include <fstream>
#include <sstream>
#include <string>
#include <thread>
#include <vector>

#ifdef _WIN32
#include <windows.h>
#include <shellapi.h>
#  ifdef _MSC_VER
#    pragma comment(lib, "shell32.lib")  // CommandLineToArgvW
#    pragma comment(lib, "advapi32.lib") // the registry
#    pragma comment(lib, "user32.lib")   // AllowSetForegroundWindow
#  endif
#else
#include <fcntl.h>
#include <poll.h>
#include <spawn.h>
#include <sys/wait.h>
#include <sys/file.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/un.h>
#include <unistd.h>
#endif

namespace {

std::string exePath()
{
#ifdef _WIN32
    std::vector<wchar_t> wide(32768);
    DWORD n = GetModuleFileNameW(nullptr, wide.data(), static_cast<DWORD>(wide.size()));
    int bytes = WideCharToMultiByte(CP_UTF8, 0, wide.data(), static_cast<int>(n), nullptr, 0, nullptr, nullptr);
    std::string path(bytes, '\0');
    WideCharToMultiByte(CP_UTF8, 0, wide.data(), static_cast<int>(n), path.data(), bytes, nullptr, nullptr);
    return path;
#else
    std::vector<char> buffer(4096);
    ssize_t n = readlink("/proc/self/exe", buffer.data(), buffer.size() - 1);
    return std::string(buffer.data(), n > 0 ? static_cast<size_t>(n) : 0);
#endif
}

/** The executable's name without .exe, kept to letters, digits, '.', '_' and '-': the app's id. */
std::string appId()
{
    std::string exe = exePath();
    std::string name = exe.substr(exe.find_last_of("\\/") + 1);
    if (name.size() > 4 && (name.compare(name.size() - 4, 4, ".exe") == 0 || name.compare(name.size() - 4, 4, ".EXE") == 0)) {
        name.resize(name.size() - 4);
    }
    std::string id;
    for (char c : name) {
        id += (std::isalnum(static_cast<unsigned char>(c)) || c == '.' || c == '_' || c == '-') ? c : '_';
    }
    return id.empty() ? "app" : id;
}

/** The arguments the process was started with (UTF-8), the program first. */
std::vector<std::string> arguments()
{
    std::vector<std::string> args;
#ifdef _WIN32
    int count = 0;
    LPWSTR *wide = CommandLineToArgvW(GetCommandLineW(), &count);
    for (int i = 0; wide != nullptr && i < count; i++) {
        int bytes = WideCharToMultiByte(CP_UTF8, 0, wide[i], -1, nullptr, 0, nullptr, nullptr);
        std::string arg(bytes > 0 ? bytes - 1 : 0, '\0');
        WideCharToMultiByte(CP_UTF8, 0, wide[i], -1, arg.data(), bytes, nullptr, nullptr);
        args.push_back(arg);
    }
    LocalFree(wide);
#else
    std::ifstream in("/proc/self/cmdline", std::ios::binary);
    std::string arg;
    while (std::getline(in, arg, '\0')) {
        args.push_back(arg);
    }
#endif
    return args;
}

std::vector<std::string> split(const std::string &list, char separator)
{
    std::vector<std::string> out;
    std::stringstream in(list);
    std::string item;
    while (std::getline(in, item, separator)) {
        if (!item.empty()) {
            out.push_back(item);
        }
    }
    return out;
}

/** The first argument that is a link with one of the schemes ('' if none). */
std::string launchLink(const std::vector<std::string> &schemes)
{
    std::vector<std::string> args = arguments();
    for (size_t i = 1; i < args.size(); i++) {
        for (const auto &scheme : schemes) {
            const std::string &a = args[i];
            if (a.size() > scheme.size() && a[scheme.size()] == ':'
                && std::equal(scheme.begin(), scheme.end(), a.begin(), [](char x, char y) { return std::tolower(x) == std::tolower(y); })) {
                return a;
            }
        }
    }
    return "";
}

/**
 * "open <url>" / "focus" from another launch, optionally followed by "token <activation token>":
 * an event for PHP on the UI thread (the token is for the raise it leads to).
 */
void deliver(const std::string &message)
{
    std::string line = message.substr(0, message.find('\n'));
    if (auto at = message.find("\ntoken "); at != std::string::npos) {
        std::string token = message.substr(at + 7);
        rk::win::set_activation_token(token.substr(0, token.find('\n')));
    }
    if (line.rfind("open ", 0) == 0) {
        std::string url = line.substr(5);
        rk_post_event("@links", 0, "open", url.data(), url.size());
    } else if (line == "focus") {
        rk_post_event("@links", 0, "focus", "", 0);
    }
}

std::atomic<bool> stopping{false};
std::thread listener;

#ifndef _WIN32

int serverFd = -1;
int wakeFds[2] = {-1, -1};
std::string socketPath;

/** A folder only this user can use (lstat: not a symlink, the user's, 0700). */
bool privateDir(const std::string &dir)
{
    struct stat st{};
    return lstat(dir.c_str(), &st) == 0 && S_ISDIR(st.st_mode) && st.st_uid == getuid() && (st.st_mode & 077) == 0;
}

/**
 * Where the socket and its lock live: $XDG_RUNTIME_DIR, else /tmp/ragekit-<uid> created 0700. ''
 * when neither is private to this user: another user could then pose as the running instance (and
 * get the links, sign-in codes included), so the app runs without single-instance handover.
 */
std::string runtimeDir()
{
    const char *dir = std::getenv("XDG_RUNTIME_DIR");
    if (dir != nullptr && *dir != '\0' && privateDir(dir)) {
        return dir;
    }
    std::string fallback = "/tmp/ragekit-" + std::to_string(getuid());
    mkdir(fallback.c_str(), 0700); // fails harmlessly if it exists; checked either way
    return privateDir(fallback) ? fallback : "";
}

bool send(const std::string &path, const std::string &message)
{
    int fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (fd < 0) {
        return false;
    }
    sockaddr_un address{};
    address.sun_family = AF_UNIX;
    std::strncpy(address.sun_path, path.c_str(), sizeof address.sun_path - 1);
    bool ok = connect(fd, reinterpret_cast<sockaddr *>(&address), sizeof address) == 0;
    if (ok) {
        size_t done = 0;
        while (done < message.size()) {
            ssize_t n = write(fd, message.data() + done, message.size() - done);
            if (n <= 0) {
                break;
            }
            done += static_cast<size_t>(n);
        }
    }
    close(fd);
    return ok;
}

void listen_()
{
    while (!stopping) {
        pollfd fds[2] = {{serverFd, POLLIN, 0}, {wakeFds[0], POLLIN, 0}};
        if (poll(fds, 2, -1) <= 0 || (fds[1].revents & POLLIN) != 0) {
            continue;
        }
        int client = accept(serverFd, nullptr, nullptr);
        if (client < 0) {
            continue;
        }
        std::string message;
        char buffer[4096];
        while (message.size() < 65536) {
            pollfd one = {client, POLLIN, 0};
            if (poll(&one, 1, 2000) <= 0) {
                break; // a launch that connected and said nothing: drop it
            }
            ssize_t n = read(client, buffer, sizeof buffer);
            if (n <= 0) {
                break;
            }
            message.append(buffer, static_cast<size_t>(n));
        }
        close(client);
        deliver(message);
    }
}

#else // Windows: untested

HANDLE stopEvent = nullptr;
std::wstring pipeName;

std::wstring wide(const std::string &utf8)
{
    int len = MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), nullptr, 0);
    std::wstring out(static_cast<size_t>(len), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), out.data(), len);
    return out;
}

bool send(const std::wstring &name, const std::string &message)
{
    HANDLE pipe = CreateFileW(name.c_str(), GENERIC_WRITE, 0, nullptr, OPEN_EXISTING, 0, nullptr);
    if (pipe == INVALID_HANDLE_VALUE) {
        return false;
    }
    // This launch came from the user (a click, a link), so it may bring a window forward; the running
    // instance may not by itself (Windows' foreground lock). Hand it the right before the message.
    ULONG server = 0;
    if (GetNamedPipeServerProcessId(pipe, &server)) {
        AllowSetForegroundWindow(server);
    }
    DWORD written = 0;
    WriteFile(pipe, message.data(), static_cast<DWORD>(message.size()), &written, nullptr);
    CloseHandle(pipe);
    return true;
}

HANDLE createPipe(bool first)
{
    return CreateNamedPipeW(pipeName.c_str(), PIPE_ACCESS_INBOUND | FILE_FLAG_OVERLAPPED | (first ? FILE_FLAG_FIRST_PIPE_INSTANCE : 0),
                            PIPE_TYPE_BYTE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS, PIPE_UNLIMITED_INSTANCES, 0, 65536, 0, nullptr);
}

void listen_(HANDLE pipe)
{
    while (!stopping) {
        OVERLAPPED overlapped{};
        overlapped.hEvent = CreateEventW(nullptr, TRUE, FALSE, nullptr);
        BOOL connected = ConnectNamedPipe(pipe, &overlapped);
        if (!connected && GetLastError() == ERROR_IO_PENDING) {
            HANDLE events[2] = {overlapped.hEvent, stopEvent};
            if (WaitForMultipleObjects(2, events, FALSE, INFINITE) != WAIT_OBJECT_0) {
                CancelIo(pipe);
                CloseHandle(overlapped.hEvent);
                break;
            }
        }
        CloseHandle(overlapped.hEvent);
        std::string message;
        char buffer[4096];
        DWORD n = 0;
        while (message.size() < 65536 && ReadFile(pipe, buffer, sizeof buffer, &n, nullptr) && n > 0) {
            message.append(buffer, n);
        }
        DisconnectNamedPipe(pipe);
        deliver(message);
    }
    CloseHandle(pipe);
}

#endif

} // namespace

extern "C" {

RK_API int rk_links_claim(const char *schemes)
{
    std::vector<std::string> list = split(schemes, ',');
    std::string link = launchLink(list);
    std::string message = link.empty() ? "focus\n" : "open " + link + "\n";
#ifndef _WIN32
    // Who started this launch (browser, desktop) may have given it the right to come forward;
    // the running instance needs it to raise its window.
    for (const char *name : {"XDG_ACTIVATION_TOKEN", "DESKTOP_STARTUP_ID"}) {
        const char *token = std::getenv(name);
        if (token != nullptr && *token != '\0' && std::strchr(token, '\n') == nullptr) {
            message += std::string("token ") + token + "\n";
            break;
        }
    }
#endif
    std::string id = appId();
#ifndef _WIN32
    std::string dir = runtimeDir();
    if (dir.empty()) {
        if (!link.empty()) {
            rk_post_event("@links", 0, "open", link.data(), link.size());
        }
        return 1; // no private place for the socket: a lone instance
    }
    std::string base = dir + "/ragekit-" + id;
    socketPath = base + ".sock";
    // Two launches at once mustn't both become the first: claiming happens under a lock.
    int lock = open((base + ".lock").c_str(), O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (lock >= 0) {
        flock(lock, LOCK_EX);
    }
    int result = 1;
    if (send(socketPath, message)) {
        result = 0; // handed over: this launch is done
    } else {
        unlink(socketPath.c_str()); // a socket left by an instance that crashed
        serverFd = socket(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0);
        sockaddr_un address{};
        address.sun_family = AF_UNIX;
        std::strncpy(address.sun_path, socketPath.c_str(), sizeof address.sun_path - 1);
        mode_t mask = umask(077); // the socket is the user's alone from the moment it exists
        bool bound = serverFd >= 0 && bind(serverFd, reinterpret_cast<sockaddr *>(&address), sizeof address) == 0;
        umask(mask);
        if (bound && listen(serverFd, 8) == 0 && pipe(wakeFds) == 0) {
            stopping = false;
            listener = std::thread(listen_);
        } else if (serverFd >= 0) {
            close(serverFd); // can't listen: still run, as a lone instance
            serverFd = -1;
        }
    }
    if (lock >= 0) {
        flock(lock, LOCK_UN);
        close(lock);
    }
#else
    pipeName = L"\\\\.\\pipe\\ragekit-" + wide(id);
    int result = 1;
    HANDLE pipe = createPipe(true);
    if (pipe == INVALID_HANDLE_VALUE) {
        if (send(pipeName, message)) {
            result = 0;
        }
    } else {
        stopEvent = CreateEventW(nullptr, TRUE, FALSE, nullptr);
        stopping = false;
        listener = std::thread(listen_, pipe);
    }
#endif
    if (result == 1) {
        // The running instance must come to the front for links: on GNOME's Wayland only with a
        // token browsers don't hand over yet, while XWayland's _NET_ACTIVE_WINDOW works.
        rk::win::prefer_x11();
    }
    if (result == 1 && !link.empty()) {
        rk_post_event("@links", 0, "open", link.data(), link.size()); // started by a link: the app opens it
    }
    return result;
}

RK_API void rk_links_stop(void)
{
    if (!listener.joinable()) {
        return;
    }
    stopping = true;
#ifndef _WIN32
    if (write(wakeFds[1], "x", 1) < 0) {
        // the poll still ends when the socket closes below
    }
    listener.join();
    // Remove the socket under the claim lock, before closing it: a launch starting now must find
    // either this instance or no socket, never have its own fresh socket deleted by us.
    std::string lockPath = socketPath.substr(0, socketPath.size() - 5) + ".lock";
    int lock = open(lockPath.c_str(), O_RDWR | O_CLOEXEC | O_NOFOLLOW);
    if (lock >= 0) {
        flock(lock, LOCK_EX);
    }
    unlink(socketPath.c_str());
    if (lock >= 0) {
        flock(lock, LOCK_UN);
        close(lock);
    }
    close(serverFd);
    close(wakeFds[0]);
    close(wakeFds[1]);
    serverFd = wakeFds[0] = wakeFds[1] = -1;
#else
    SetEvent(stopEvent);
    listener.join();
    CloseHandle(stopEvent);
#endif
}

RK_API int64_t rk_utc_offset(int64_t at)
{
    time_t t = static_cast<time_t>(at);
#ifndef _WIN32
    struct tm local {};
    localtime_r(&t, &local);
    return local.tm_gmtoff;
#else // untested
    struct tm local {}, utc {};
    localtime_s(&local, &t);
    gmtime_s(&utc, &t);
    local.tm_isdst = -1;
    return static_cast<int64_t>(std::difftime(_mkgmtime(&local), _mkgmtime(&utc)));
#endif
}

RK_API size_t rk_open_url(const char *url, char *error, size_t size)
{
    std::string problem;
#ifndef _WIN32
    extern char **environ;
    pid_t pid = 0;
    char *argv[] = {const_cast<char *>("xdg-open"), const_cast<char *>(url), nullptr};
    int failed = posix_spawnp(&pid, "xdg-open", nullptr, nullptr, argv, environ);
    if (failed != 0) {
        problem = std::string("can't run xdg-open: ") + std::strerror(failed);
    } else {
        std::thread([pid] { // xdg-open returns quickly; reaping it keeps no zombie around
            int status = 0;
            waitpid(pid, &status, 0);
        }).detach();
    }
#else // untested
    std::wstring target = wide(url);
    if (reinterpret_cast<intptr_t>(ShellExecuteW(nullptr, L"open", target.c_str(), nullptr, nullptr, SW_SHOWNORMAL)) <= 32) {
        problem = "Windows couldn't open the link";
    }
#endif
    if (size > 0) {
        std::snprintf(error, size, "%s", problem.c_str());
    }
    return problem.size();
}

RK_API size_t rk_links_register(const char *scheme, char *error, size_t size)
{
    std::string problem;
    std::string exe = exePath();
    std::string id = appId();
#ifndef _WIN32
    namespace fs = std::filesystem;
    const char *home = std::getenv("HOME");
    const char *data = std::getenv("XDG_DATA_HOME");
    const char *config = std::getenv("XDG_CONFIG_HOME");
    fs::path apps = (data != nullptr && *data != '\0') ? fs::path(data) / "applications" : fs::path(home != nullptr ? home : ".") / ".local/share/applications";
    fs::path configDir = (config != nullptr && *config != '\0') ? fs::path(config) : fs::path(home != nullptr ? home : ".") / ".config";
    std::string desktopId = "ragekit-" + id + ".desktop";
    std::error_code ec;
    fs::create_directories(apps, ec);
    fs::create_directories(configDir, ec);
    // The .desktop file lists every scheme registered so far.
    std::vector<std::string> mimes;
    {
        std::ifstream old(apps / desktopId);
        std::string line;
        while (std::getline(old, line)) {
            if (line.rfind("MimeType=", 0) == 0) {
                mimes = split(line.substr(9), ';');
            }
        }
    }
    std::string mime = std::string("x-scheme-handler/") + scheme;
    if (std::find(mimes.begin(), mimes.end(), mime) == mimes.end()) {
        mimes.push_back(mime);
    }
    // Exec=: the Exec quoting rules first (\ before " ` $ \ inside the quotes, %% for %), then the
    // general string escaping of every value (\ becomes \\), as the Desktop Entry spec layers them.
    std::string exec = "\"";
    for (char c : exe) {
        if (c == '"' || c == '`' || c == '$' || c == '\\') {
            exec += '\\';
        }
        exec += c == '%' ? std::string("%%") : std::string(1, c);
    }
    exec += '"';
    std::string quoted;
    for (char c : exec) {
        quoted += c == '\\' ? std::string("\\\\") : std::string(1, c);
    }
    std::ostringstream desktop;
    desktop << "[Desktop Entry]\nType=Application\nName=" << id << "\nExec=" << quoted << " %u\nTerminal=false\nNoDisplay=true\n";
    // The app's icon (project.yml ragekit: icon:), which the build copies next to the executable.
    fs::path exeDir = fs::path(exe).parent_path();
    for (const char *name : {"icon.png", "icon.svg"}) {
        if (fs::exists(exeDir / name, ec)) {
            desktop << "Icon=" << (exeDir / name).string() << "\n";
            break;
        }
    }
    desktop << "MimeType=";
    for (const auto &m : mimes) {
        desktop << m << ';';
    }
    desktop << "\n";
    auto write = [&](const fs::path &given, const std::string &text) {
        // A symlinked file (dotfiles managers) is written where it points, keeping the link; its
        // permissions stay as they were.
        fs::path path = given;
        if (fs::is_symlink(given, ec)) {
            path = fs::weakly_canonical(given, ec);
        }
        fs::path tmp = path;
        tmp += ".part";
        {
            std::ofstream out(tmp, std::ios::binary | std::ios::trunc);
            out << text;
            if (!out) {
                problem = "can't write " + path.string();
                return;
            }
        }
        if (fs::exists(path, ec)) {
            fs::permissions(tmp, fs::status(path, ec).permissions(), ec);
        }
        fs::rename(tmp, path, ec);
        if (ec) {
            problem = "can't write " + path.string() + ": " + ec.message();
        }
    };
    write(apps / desktopId, desktop.str());
    // mimeapps.list: x-scheme-handler/<scheme>=<our desktop file> under [Default Applications].
    if (problem.empty()) {
        std::vector<std::string> lines;
        {
            std::ifstream in(configDir / "mimeapps.list");
            std::string line;
            while (std::getline(in, line)) {
                lines.push_back(line);
            }
        }
        std::string entry = mime + "=" + desktopId;
        bool inDefaults = false, done = false;
        std::vector<std::string> out;
        for (size_t i = 0; i < lines.size(); i++) {
            const std::string &line = lines[i];
            bool header = !line.empty() && line[0] == '[';
            if (header && inDefaults && !done) {
                out.push_back(entry); // the section ended without one
                done = true;
            }
            if (header) {
                inDefaults = line == "[Default Applications]";
            }
            if (inDefaults && line.rfind(mime + "=", 0) == 0) {
                if (!done) {
                    out.push_back(entry);
                    done = true;
                }
                continue;
            }
            out.push_back(line);
        }
        if (!done) {
            if (!inDefaults) {
                out.push_back("[Default Applications]");
            }
            out.push_back(entry);
        }
        std::string text;
        for (const auto &line : out) {
            text += line + "\n";
        }
        write(configDir / "mimeapps.list", text);
    }
#else
    std::wstring key = L"Software\\Classes\\" + wide(scheme);
    std::wstring command = L"\"" + wide(exe) + L"\" \"%1\"";
    std::wstring description = L"URL:" + wide(scheme);
    HKEY root = nullptr, cmd = nullptr;
    if (RegCreateKeyExW(HKEY_CURRENT_USER, key.c_str(), 0, nullptr, 0, KEY_WRITE, nullptr, &root, nullptr) != ERROR_SUCCESS
        || RegSetValueExW(root, nullptr, 0, REG_SZ, reinterpret_cast<const BYTE *>(description.c_str()), static_cast<DWORD>((description.size() + 1) * sizeof(wchar_t))) != ERROR_SUCCESS
        || RegSetValueExW(root, L"URL Protocol", 0, REG_SZ, reinterpret_cast<const BYTE *>(L""), sizeof(wchar_t)) != ERROR_SUCCESS
        || RegCreateKeyExW(root, L"shell\\open\\command", 0, nullptr, 0, KEY_WRITE, nullptr, &cmd, nullptr) != ERROR_SUCCESS
        || RegSetValueExW(cmd, nullptr, 0, REG_SZ, reinterpret_cast<const BYTE *>(command.c_str()), static_cast<DWORD>((command.size() + 1) * sizeof(wchar_t))) != ERROR_SUCCESS) {
        problem = "can't write the registry key HKCU\\" + std::string(key.begin(), key.end());
    }
    if (cmd != nullptr) {
        RegCloseKey(cmd);
    }
    if (root != nullptr) {
        RegCloseKey(root);
    }
#endif
    if (size > 0) {
        std::snprintf(error, size, "%s", problem.c_str());
    }
    return problem.size();
}

} // extern "C"
