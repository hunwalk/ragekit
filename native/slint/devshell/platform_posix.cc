#ifndef _WIN32

#include "platform.h"

#include <cerrno>
#include <csignal>
#include <cstdlib>
#include <spawn.h>
#include <sys/wait.h>

extern char **environ;

namespace rk::dev::platform {

bool spawn(const std::vector<std::string> &argv, Handle &process)
{
    std::vector<char *> args;
    for (const auto &arg : argv) {
        args.push_back(const_cast<char *>(arg.c_str()));
    }
    args.push_back(nullptr);
    pid_t pid = 0;
    if (posix_spawnp(&pid, args[0], nullptr, nullptr, args.data(), environ) != 0) {
        return false;
    }
    process = pid;
    return true;
}

bool running(Handle process)
{
    siginfo_t info{};
    // WNOWAIT leaves it to wait(); si_pid stays 0 while it runs.
    return waitid(P_PID, static_cast<id_t>(process), &info, WEXITED | WNOHANG | WNOWAIT) == 0 && info.si_pid == 0;
}

int wait(Handle process)
{
    siginfo_t info{};
    while (waitid(P_PID, static_cast<id_t>(process), &info, WEXITED | WNOWAIT) < 0 && errno == EINTR) {
    }
    return info.si_code == CLD_EXITED ? info.si_status : 128 + info.si_status;
}

void release(Handle process)
{
    while (waitpid(static_cast<pid_t>(process), nullptr, 0) < 0 && errno == EINTR) {
    }
}

void terminate(Handle process)
{
    ::kill(static_cast<pid_t>(process), SIGTERM);
}

void setEnv(const std::string &name, const std::string &value)
{
    ::setenv(name.c_str(), value.c_str(), 1);
}

void unsetEnv(const std::string &name)
{
    ::unsetenv(name.c_str());
}

std::vector<std::string> arguments(int argc, char **argv)
{
    return std::vector<std::string>(argv, argv + argc);
}

} // namespace rk::dev::platform

#endif
