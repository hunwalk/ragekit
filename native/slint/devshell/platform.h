// What differs per OS (platform_posix.cc, platform_win32.cc): starting, watching and stopping
// the PHP child, the environment it inherits, and main()'s arguments.
#pragma once

#include <cstdint>
#include <string>
#include <vector>

namespace rk::dev::platform {

using Handle = std::intptr_t; // a pid, or a process HANDLE on Windows

// argv[0] is looked up on PATH; the child inherits stdin/stdout/stderr and the environment.
bool spawn(const std::vector<std::string> &argv, Handle &process);
bool running(Handle process);   // doesn't reap it
int wait(Handle process);       // blocks until it ends and returns its exit code; doesn't reap it
void release(Handle process);   // reaps it: the handle (pid) is invalid afterwards
void terminate(Handle process); // before release() only

void setEnv(const std::string &name, const std::string &value);
void unsetEnv(const std::string &name);

// main()'s arguments in UTF-8 (on Windows, argv is in the ANSI code page: read the wide ones).
std::vector<std::string> arguments(int argc, char **argv);

} // namespace rk::dev::platform
