// TCP on 127.0.0.1, the dev shell's links to PHP and to `ragekit dev`. Each side proves itself
// with a token sent as the first line, which the other side passes through the environment.
// Sockets are created non-inheritable, so a child process gets none of them.
#pragma once

#include <cstdint>
#include <string>

namespace rk::dev::loopback {

using Socket = std::intptr_t; // a SOCKET on Windows, an fd elsewhere
constexpr Socket none = -1;

Socket listen(int &port);                    // a free port on 127.0.0.1
Socket connect(const std::string &address);  // "127.0.0.1:port"; none on failure
Socket accept(Socket listener);
bool readable(Socket socket, int timeoutMs); // data or EOF waiting (false on timeout)
long receive(Socket socket, char *buffer, size_t size); // <= 0: closed or failed
bool send(Socket socket, const std::string &data);
void shutdown(Socket socket); // wakes a thread blocked in receive(); the owner still closes
void close(Socket socket);
std::string token(); // 32 random hex digits

// Reads until buffer holds a whole line and moves it (without "\n") to line; bytes after it stay
// in buffer. false on EOF, error or after timeoutMs without data (-1: wait as long as it takes).
bool readLine(Socket socket, std::string &buffer, std::string &line, int timeoutMs);

} // namespace rk::dev::loopback
