// The app's PHP, run as the dev shell's child: fd 3 carries messages to PHP, fd 4 messages from
// it; stdin/stdout/stderr are inherited so the app's own output reaches the terminal.
#pragma once

#include <atomic>
#include <functional>
#include <string>
#include <utility>
#include <vector>

namespace rk::dev {

class Child {
public:
    // Both callbacks run on a reader thread and get the generation they belong to: a restart
    // starts a new generation, so late lines/exits of a killed child can be told apart.
    using LineFn = std::function<void(unsigned generation, std::string line)>;
    using ExitFn = std::function<void(unsigned generation, int code)>;

    bool start(const std::vector<std::string> &argv, LineFn on_line, ExitFn on_exit);
    void send(const std::string &line); // appends "\n"; ignored when no child is running
    void kill();                        // SIGTERM; its exit still arrives (old generation)
    unsigned generation() const { return generation_; }

private:
    int pid_ = -1;
    int to_ = -1;
    std::atomic<unsigned> generation_{0};
};

} // namespace rk::dev
