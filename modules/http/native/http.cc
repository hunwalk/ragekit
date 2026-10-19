// The http module's library: each request runs on its own worker thread (transfer.h does the
// HTTP), and its outcome is posted as an event for the request's id:
//   progress  "<received> <total>" (total -1 when unknown), at most every 100 ms
//   done      status line, "Name: value" header lines, an empty line, then the body (any bytes;
//             empty for downloads)
//   error     a message: no response (network, TLS, timeout, bad URL, file not writable)
// A cancelled request posts nothing more once http_cancel() returns.
#include "rk_module_http.h"
#include "transfer.h"

#include <atomic>
#include <chrono>
#include <cstdio>
#include <filesystem>
#include <functional>
#include <fstream>
#include <map>
#include <memory>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

namespace {

struct Request {
    std::atomic<bool> cancelled{false};
    std::atomic<bool> finished{false};
    std::thread thread;
    std::mutex aborting; // guards abort
    std::function<void()> abort;

    void cancel()
    {
        cancelled = true;
        std::lock_guard<std::mutex> lock(aborting);
        if (abort) {
            abort();
        }
    }
};

// Lock order: posting, then state. A worker posts while holding `posting` and checks
// `cancelled` under it, so once cancel()/reset() have set the flag under that lock, nothing
// more is posted for the request.
std::mutex posting;
std::mutex state;
std::map<int64_t, std::shared_ptr<Request>> requests;
int64_t nextId = 1;
rk_post_fn post = nullptr;

void send(int64_t id, const Request &request, const char *kind, const std::string &data)
{
    std::lock_guard<std::mutex> lock(posting);
    if (!request.cancelled && post != nullptr) {
        post("http", id, kind, data.data(), data.size());
    }
}

std::vector<std::string> lines(const std::string &text)
{
    std::vector<std::string> out;
    size_t start = 0;
    while (start < text.size()) {
        size_t end = text.find('\n', start);
        if (end == std::string::npos) {
            end = text.size();
        }
        std::string line = text.substr(start, end - start);
        if (!line.empty() && line.back() == '\r') {
            line.pop_back();
        }
        if (!line.empty()) {
            out.push_back(line);
        }
        start = end + 1;
    }
    return out;
}

void run(int64_t id, std::shared_ptr<Request> request, rkhttp::Transfer transfer, std::string file)
{
    std::string body;
    std::ofstream out;
    std::filesystem::path target;
    std::filesystem::path part;
    std::string fileError;
    if (!file.empty()) {
        target = std::filesystem::u8path(file);
        part = target;
        part += ".part";
        std::error_code ignored;
        if (target.has_parent_path()) {
            std::filesystem::create_directories(target.parent_path(), ignored);
        }
        out.open(part, std::ios::binary | std::ios::trunc);
        if (!out) {
            send(id, *request, "error", "can't write " + file);
            request->finished = true;
            return;
        }
    }

    auto lastProgress = std::chrono::steady_clock::now() - std::chrono::seconds(1);
    transfer.onData = [&](const char *data, size_t len) {
        if (request->cancelled) {
            return false;
        }
        if (out.is_open()) {
            out.write(data, static_cast<std::streamsize>(len));
            if (!out) {
                fileError = "can't write " + file;
                return false;
            }
        } else {
            body.append(data, len);
        }
        return true;
    };
    transfer.onProgress = [&](int64_t received, int64_t total) {
        if (request->cancelled) {
            return false;
        }
        auto now = std::chrono::steady_clock::now();
        if (received > 0 && now - lastProgress >= std::chrono::milliseconds(100)) {
            lastProgress = now;
            send(id, *request, "progress", std::to_string(received) + " " + std::to_string(total));
        }
        return true;
    };

    transfer.setAbort = [request](std::function<void()> abort) {
        std::lock_guard<std::mutex> lock(request->aborting);
        request->abort = std::move(abort);
        if (request->cancelled && request->abort) {
            request->abort();
        }
    };

    rkhttp::Result result = rkhttp::perform(transfer);

    bool saved = false;
    if (out.is_open()) {
        out.close();
        std::error_code ignored;
        if (!result.aborted && result.error.empty() && result.status >= 200 && result.status < 300) {
            std::filesystem::remove(target, ignored); // rename() won't replace a file on Windows
            std::filesystem::rename(part, target, ignored);
            saved = !ignored;
            if (!saved) {
                fileError = "can't write " + file;
            }
        }
        if (!saved) {
            std::filesystem::remove(part, ignored);
        }
    }

    if (!fileError.empty()) {
        send(id, *request, "error", fileError);
    } else if (result.aborted) {
        // cancelled: nothing to report
    } else if (!result.error.empty()) {
        send(id, *request, "error", result.error);
    } else {
        std::string data = std::to_string(result.status) + "\n";
        for (const auto &header : result.headers) {
            data += header + "\n";
        }
        data += "\n" + body;
        send(id, *request, "done", data);
    }
    request->finished = true;
}

/** Joins and forgets requests whose thread has ended. Called with `state` held. */
void reap()
{
    for (auto it = requests.begin(); it != requests.end();) {
        if (it->second->finished) {
            it->second->thread.join();
            it = requests.erase(it);
        } else {
            ++it;
        }
    }
}

void cancelAll()
{
    std::vector<std::shared_ptr<Request>> all;
    {
        std::lock_guard<std::mutex> sending(posting);
        std::lock_guard<std::mutex> lock(state);
        for (auto &[id, request] : requests) {
            request->cancel();
            all.push_back(request);
        }
        requests.clear();
    }
    for (auto &request : all) {
        request->thread.join(); // a cancelled transfer stops at its next progress check
    }
}

// The app normally resets modules when its event loop ends; if it exits some other way, the
// workers must still be joined before their std::thread objects are destroyed.
struct ShutdownGuard {
    ~ShutdownGuard() { cancelAll(); }
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_http_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_http_reset(void)
{
    cancelAll();
}

RKM_API int64_t rkm_http_request(const char *method, size_t method_len, const char *url, size_t url_len,
    const char *headers, size_t headers_len, const char *body, size_t body_len, const char *file, size_t file_len,
    double timeout)
{
    rkhttp::Transfer transfer;
    transfer.method = std::string(method, method_len);
    transfer.url = std::string(url, url_len);
    transfer.headers = lines(std::string(headers, headers_len));
    transfer.body = std::string(body, body_len);
    transfer.timeout = timeout > 0 && timeout < 86400 ? timeout : 30;

    auto request = std::make_shared<Request>();
    std::lock_guard<std::mutex> lock(state);
    reap();
    int64_t id = nextId++;
    requests.emplace(id, request);
    request->thread = std::thread(run, id, request, std::move(transfer), std::string(file, file_len));
    return id;
}

RKM_API void rkm_http_cancel(int64_t request)
{
    std::lock_guard<std::mutex> sending(posting);
    std::lock_guard<std::mutex> lock(state);
    auto it = requests.find(request);
    if (it != requests.end()) {
        it->second->cancel(); // its thread is joined by reap() or reset
    }
}

} // extern "C"
