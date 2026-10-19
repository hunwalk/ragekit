// One HTTP exchange, run synchronously on a worker thread: transfer_curl.cc (Linux, macOS) or
// transfer_winhttp.cc (Windows) implements perform().
#pragma once

#include <cstddef>
#include <cstdint>
#include <functional>
#include <string>
#include <vector>

namespace rkhttp {

struct Transfer {
    std::string method;
    std::string url;
    std::vector<std::string> headers; // "Name: value"
    std::string body;
    double timeout = 30; // seconds without progress
    // Return false to abort (the request was cancelled, or the file can't be written).
    std::function<bool(const char *data, size_t len)> onData;
    std::function<bool(int64_t received, int64_t total)> onProgress; // total -1: unknown
    // For transports whose blocking calls don't check onProgress (WinHTTP): perform() hands over
    // a function that aborts it from another thread, and an empty one before it returns. It is
    // called right away if the request was already cancelled.
    std::function<void(std::function<void()>)> setAbort;
};

struct Result {
    bool aborted = false;     // onData / onProgress returned false
    std::string error;        // non-empty: no response (network, TLS, timeout, bad URL)
    int status = 0;
    std::vector<std::string> headers; // of the final response, "Name: value"
};

Result perform(const Transfer &transfer);

} // namespace rkhttp
