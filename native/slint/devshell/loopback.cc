#include "loopback.h"

#ifdef _WIN32
#define WIN32_LEAN_AND_MEAN
#define NOMINMAX
#include <winsock2.h>
#include <ws2tcpip.h>
#include <windows.h>
#else
#include <arpa/inet.h>
#include <cerrno>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <poll.h>
#include <sys/socket.h>
#include <unistd.h>
#endif

#include <cstdio>
#include <cstdlib>
#include <mutex>
#include <random>

namespace rk::dev::loopback {

namespace {

#ifdef _WIN32
using Native = SOCKET;
constexpr Native invalid = INVALID_SOCKET;

void startup()
{
    static std::once_flag once;
    std::call_once(once, [] {
        WSADATA data;
        WSAStartup(MAKEWORD(2, 2), &data);
    });
}

Native open()
{
    startup();
    return WSASocketW(AF_INET, SOCK_STREAM, IPPROTO_TCP, nullptr, 0, WSA_FLAG_OVERLAPPED | WSA_FLAG_NO_HANDLE_INHERIT);
}

bool interrupted()
{
    return false;
}
#else
using Native = int;
constexpr Native invalid = -1;

Native open()
{
    return ::socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0);
}

bool interrupted()
{
    return errno == EINTR;
}
#endif

Native native(Socket socket)
{
    return static_cast<Native>(socket);
}

Socket wrap(Native socket)
{
    return socket == invalid ? none : static_cast<Socket>(socket);
}

sockaddr_in localhost(int port)
{
    sockaddr_in address{};
    address.sin_family = AF_INET;
    address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    address.sin_port = htons(static_cast<uint16_t>(port));
    return address;
}

// Requests and replies are single short lines: don't let Nagle hold them back.
void noDelay(Native socket)
{
    int on = 1;
    setsockopt(socket, IPPROTO_TCP, TCP_NODELAY, reinterpret_cast<const char *>(&on), sizeof on);
}

} // namespace

Socket listen(int &port)
{
    Native socket = open();
    if (socket == invalid) {
        return none;
    }
    sockaddr_in address = localhost(0);
    socklen_t length = sizeof address;
    if (::bind(socket, reinterpret_cast<sockaddr *>(&address), sizeof address) != 0
        || ::listen(socket, 4) != 0
        || ::getsockname(socket, reinterpret_cast<sockaddr *>(&address), &length) != 0) {
        close(wrap(socket));
        return none;
    }
    port = ntohs(address.sin_port);
    return wrap(socket);
}

Socket connect(const std::string &address)
{
    auto colon = address.rfind(':');
    if (colon == std::string::npos || address.substr(0, colon) != "127.0.0.1") {
        return none;
    }
    Native socket = open();
    if (socket == invalid) {
        return none;
    }
    sockaddr_in to = localhost(std::atoi(address.c_str() + colon + 1));
    if (::connect(socket, reinterpret_cast<sockaddr *>(&to), sizeof to) != 0) {
        close(wrap(socket));
        return none;
    }
    noDelay(socket);
    return wrap(socket);
}

Socket accept(Socket listener)
{
#ifdef _WIN32
    Native socket = ::accept(native(listener), nullptr, nullptr);
    if (socket != invalid) {
        SetHandleInformation(reinterpret_cast<HANDLE>(socket), HANDLE_FLAG_INHERIT, 0);
    }
#else
    Native socket;
    do {
        socket = ::accept4(native(listener), nullptr, nullptr, SOCK_CLOEXEC);
    } while (socket == invalid && interrupted());
#endif
    if (socket != invalid) {
        noDelay(socket);
    }
    return wrap(socket);
}

bool readable(Socket socket, int timeoutMs)
{
#ifdef _WIN32
    WSAPOLLFD poll{native(socket), POLLRDNORM, 0};
    return WSAPoll(&poll, 1, timeoutMs) > 0;
#else
    pollfd poll{native(socket), POLLIN, 0};
    int n;
    do {
        n = ::poll(&poll, 1, timeoutMs);
    } while (n < 0 && interrupted());
    return n > 0;
#endif
}

long receive(Socket socket, char *buffer, size_t size)
{
    for (;;) {
        long n = ::recv(native(socket), buffer, static_cast<int>(size), 0);
        if (n < 0 && interrupted()) {
            continue;
        }
        return n;
    }
}

bool send(Socket socket, const std::string &data)
{
    size_t done = 0;
    while (done < data.size()) {
        long n = ::send(native(socket), data.data() + done, static_cast<int>(data.size() - done), 0);
        if (n < 0 && interrupted()) {
            continue;
        }
        if (n <= 0) {
            return false;
        }
        done += static_cast<size_t>(n);
    }
    return true;
}

void shutdown(Socket socket)
{
#ifdef _WIN32
    ::shutdown(native(socket), SD_BOTH);
#else
    ::shutdown(native(socket), SHUT_RDWR);
#endif
}

void close(Socket socket)
{
    if (socket == none) {
        return;
    }
#ifdef _WIN32
    ::closesocket(native(socket));
#else
    ::close(native(socket));
#endif
}

std::string token()
{
    std::random_device random; // the OS's generator (rand_s on Windows, getrandom on Linux)
    std::string out;
    char digits[9];
    for (int i = 0; i < 4; ++i) {
        std::snprintf(digits, sizeof digits, "%08x", static_cast<unsigned>(random()));
        out += digits;
    }
    return out;
}

bool readLine(Socket socket, std::string &buffer, std::string &line, int timeoutMs)
{
    char chunk[4096];
    for (;;) {
        auto newline = buffer.find('\n');
        if (newline != std::string::npos) {
            line = buffer.substr(0, newline);
            buffer.erase(0, newline + 1);
            return true;
        }
        if (timeoutMs >= 0 && !readable(socket, timeoutMs)) {
            return false;
        }
        long n = receive(socket, chunk, sizeof chunk);
        if (n <= 0) {
            return false;
        }
        buffer.append(chunk, static_cast<size_t>(n));
    }
}

} // namespace rk::dev::loopback
