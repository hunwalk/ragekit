#include "child.h"

#include <cerrno>
#include <csignal>
#include <fcntl.h>
#include <spawn.h>
#include <sys/wait.h>
#include <thread>
#include <unistd.h>

extern char **environ;

namespace rk::dev {

namespace {

// A close-on-exec copy of fd numbered 10 or above, so dup2() onto 3 and 4 can't clobber it.
int high(int fd)
{
    int copy = fcntl(fd, F_DUPFD_CLOEXEC, 10);
    close(fd);
    return copy;
}

} // namespace

bool Child::start(const std::vector<std::string> &argv, LineFn on_line, ExitFn on_exit)
{
    int toChild[2];
    int fromChild[2];
    if (pipe(toChild) != 0 || pipe(fromChild) != 0) {
        return false;
    }
    int childIn = high(toChild[0]);
    int parentOut = high(toChild[1]);
    int parentIn = high(fromChild[0]);
    int childOut = high(fromChild[1]);

    posix_spawn_file_actions_t actions;
    posix_spawn_file_actions_init(&actions);
    posix_spawn_file_actions_adddup2(&actions, childIn, 3);
    posix_spawn_file_actions_adddup2(&actions, childOut, 4);
    std::vector<char *> args;
    for (const auto &arg : argv) {
        args.push_back(const_cast<char *>(arg.c_str()));
    }
    args.push_back(nullptr);
    pid_t pid = 0;
    int error = posix_spawnp(&pid, args[0], &actions, nullptr, args.data(), environ);
    posix_spawn_file_actions_destroy(&actions);
    close(childIn);
    close(childOut);
    if (error != 0) {
        close(parentOut);
        close(parentIn);
        return false;
    }
    unsigned generation = ++generation_;
    pid_ = pid;
    to_ = parentOut;
    std::thread([pid, parentIn, generation, on_line, on_exit] {
        std::string buffer;
        char chunk[4096];
        for (;;) {
            ssize_t n = read(parentIn, chunk, sizeof chunk);
            if (n < 0 && errno == EINTR) {
                continue;
            }
            if (n <= 0) {
                break;
            }
            buffer.append(chunk, static_cast<size_t>(n));
            for (size_t newline; (newline = buffer.find('\n')) != std::string::npos;) {
                on_line(generation, buffer.substr(0, newline));
                buffer.erase(0, newline + 1);
            }
        }
        close(parentIn);
        int status = 0;
        waitpid(pid, &status, 0);
        on_exit(generation, WIFEXITED(status) ? WEXITSTATUS(status) : 128 + WTERMSIG(status));
    }).detach();
    return true;
}

void Child::send(const std::string &line)
{
    if (to_ < 0) {
        return;
    }
    std::string data = line + "\n";
    size_t done = 0;
    while (done < data.size()) {
        ssize_t n = write(to_, data.data() + done, data.size() - done);
        if (n < 0 && errno == EINTR) {
            continue;
        }
        if (n <= 0) {
            return; // the child is gone (EPIPE): its exit arrives through the reader thread
        }
        done += static_cast<size_t>(n);
    }
}

void Child::kill()
{
    if (pid_ > 0) {
        ::kill(pid_, SIGTERM);
    }
    if (to_ >= 0) {
        close(to_);
    }
    pid_ = -1;
    to_ = -1;
    ++generation_;
}

} // namespace rk::dev
