#include "child.h"

#include "loopback.h"
#include "platform.h"

#include <mutex>
#include <thread>

namespace rk::dev {

// Shared by the Child and its reader thread; the lock guards everything below it.
struct Child::Link {
    std::mutex lock;
    platform::Handle process = 0;
    loopback::Socket socket = loopback::none; // set while PHP is connected
    std::string pending;                      // sent before PHP connected
    bool done = false;                        // PHP disconnected (or never connected)
    bool exited = false;                      // process released: don't signal it
    bool killed = false;
};

namespace {

// The connection that sends the token first; none once the child ended without one.
loopback::Socket waitForPhp(loopback::Socket listener, const std::string &token, platform::Handle child, std::string &buffer)
{
    for (;;) {
        if (!loopback::readable(listener, 100)) {
            if (!platform::running(child)) {
                return loopback::none;
            }
            continue;
        }
        loopback::Socket socket = loopback::accept(listener);
        if (socket == loopback::none) {
            continue;
        }
        std::string line;
        buffer.clear();
        if (loopback::readLine(socket, buffer, line, 5000) && line == token) {
            return socket;
        }
        loopback::close(socket); // not our PHP
    }
}

} // namespace

bool Child::start(const std::vector<std::string> &argv, LineFn on_line, ExitFn on_exit)
{
    int port = 0;
    loopback::Socket listener = loopback::listen(port);
    if (listener == loopback::none) {
        return false;
    }
    std::string token = loopback::token();
    platform::setEnv("RAGEKIT_DEV_CHANNEL", "127.0.0.1:" + std::to_string(port));
    platform::setEnv("RAGEKIT_DEV_TOKEN", token);
    auto link = std::make_shared<Link>();
    if (!platform::spawn(argv, link->process)) {
        loopback::close(listener);
        return false;
    }
    unsigned generation = ++generation_;
    link_ = link;
    std::thread([link, listener, token, generation, on_line, on_exit] {
        std::string buffer;
        loopback::Socket socket = waitForPhp(listener, token, link->process, buffer);
        loopback::close(listener);
        bool connected = false;
        {
            std::lock_guard<std::mutex> guard(link->lock);
            if (socket != loopback::none && !link->killed) {
                loopback::send(socket, link->pending);
                link->socket = socket;
                connected = true;
            }
            link->pending.clear();
        }
        for (std::string line; connected && loopback::readLine(socket, buffer, line, -1);) {
            on_line(generation, line);
        }
        {
            std::lock_guard<std::mutex> guard(link->lock);
            link->socket = loopback::none;
            link->done = true;
        }
        loopback::close(socket);
        int code = platform::wait(link->process);
        {
            std::lock_guard<std::mutex> guard(link->lock);
            platform::release(link->process);
            link->exited = true;
        }
        on_exit(generation, code);
    }).detach();
    return true;
}

void Child::send(const std::string &line)
{
    if (!link_) {
        return;
    }
    std::lock_guard<std::mutex> guard(link_->lock);
    if (link_->socket != loopback::none) {
        loopback::send(link_->socket, line + "\n"); // a failure means PHP is gone: its exit arrives through the reader thread
    } else if (!link_->done) {
        link_->pending += line + "\n";
    }
}

void Child::kill()
{
    if (link_) {
        std::lock_guard<std::mutex> guard(link_->lock);
        link_->killed = true;
        if (!link_->exited) {
            platform::terminate(link_->process);
        }
        if (link_->socket != loopback::none) {
            loopback::shutdown(link_->socket);
        }
    }
    link_.reset();
    ++generation_;
}

} // namespace rk::dev
