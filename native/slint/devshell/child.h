// The app's PHP, run as the dev shell's child. Messages travel over a loopback connection that
// PHP opens to RAGEKIT_DEV_CHANNEL, sending RAGEKIT_DEV_TOKEN first (src/Slint/Dev/Channel.php);
// stdin/stdout/stderr are inherited so the app's own output reaches the terminal.
#pragma once

#include <atomic>
#include <functional>
#include <memory>
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
    void send(const std::string &line); // appends "\n"; held until PHP connects; ignored when no child is running
    void kill();                        // SIGTERM (TerminateProcess); its exit still arrives (old generation)
    unsigned generation() const { return generation_; }

    struct Link; // shared with the reader thread (child.cc)

private:
    std::shared_ptr<Link> link_;
    std::atomic<unsigned> generation_{0};
};

} // namespace rk::dev
