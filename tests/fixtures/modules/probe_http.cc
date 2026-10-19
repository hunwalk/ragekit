// Drives the http module's library like PHP would (HttpLibraryTest) against the test server in
// tests/fixtures/http/router.php: "result <name> <kind> <data>" lines (data escaped: \0 \n \t \\),
// "progress <name> <count>" and "check <name> <value>" lines.
#include <dlfcn.h>

#include <chrono>
#include <condition_variable>
#include <cstdio>
#include <map>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

typedef void (*rk_post_fn)(const char *, int64_t, const char *, const char *, size_t);
typedef int64_t (*request_fn)(const char *, size_t, const char *, size_t, const char *, size_t, const char *, size_t,
    const char *, size_t, double);

struct Outcome {
    std::string kind; // "" until done / error
    std::string data;
    int progress = 0;
    int afterEnd = 0; // events after done / error
};

std::mutex mutex;
std::condition_variable changed;
std::map<int64_t, Outcome> outcomes;

void post(const char *, int64_t handle, const char *kind, const char *data, size_t len)
{
    std::lock_guard<std::mutex> lock(mutex);
    Outcome &o = outcomes[handle];
    if (!o.kind.empty()) {
        o.afterEnd++;
    } else if (std::string(kind) == "progress") {
        o.progress++;
    } else {
        o.kind = kind;
        o.data.assign(data, len);
    }
    changed.notify_all();
}

std::string escape(const std::string &s)
{
    std::string out;
    for (char c : s) {
        switch (c) {
        case '\0': out += "\\0"; break;
        case '\n': out += "\\n"; break;
        case '\t': out += "\\t"; break;
        case '\\': out += "\\\\"; break;
        default: out += c;
        }
    }
    return out;
}

/** Waits up to ms for the request to end; returns its outcome (kind "" = still running). */
Outcome wait(int64_t id, int ms)
{
    std::unique_lock<std::mutex> lock(mutex);
    changed.wait_for(lock, std::chrono::milliseconds(ms), [&] { return !outcomes[id].kind.empty(); });
    return outcomes[id];
}

void say(const std::string &line)
{
    std::printf("%s\n", line.c_str());
    std::fflush(stdout);
}

void report(const std::string &name, int64_t id, int ms = 10000)
{
    Outcome o = wait(id, ms);
    say("result " + name + " " + (o.kind.empty() ? "none" : o.kind) + " " + escape(o.data));
    say("progress " + name + " " + std::to_string(o.progress));
}

long millisSince(std::chrono::steady_clock::time_point start)
{
    return static_cast<long>(std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - start).count());
}

int main(int, char **argv)
{
    void *lib = dlopen(argv[1], RTLD_NOW);
    if (lib == nullptr) {
        std::printf("dlopen failed: %s\n", dlerror());
        return 1;
    }
    auto init = reinterpret_cast<void (*)(rk_post_fn)>(dlsym(lib, "rkm_http_init"));
    auto reset = reinterpret_cast<void (*)()>(dlsym(lib, "rkm_http_reset"));
    auto request = reinterpret_cast<request_fn>(dlsym(lib, "rkm_http_request"));
    auto cancel = reinterpret_cast<void (*)(int64_t)>(dlsym(lib, "rkm_http_cancel"));
    std::string base = argv[2]; // http://127.0.0.1:<port>
    std::string dir = argv[3];  // where downloads go
    init(post);

    auto start = [&](const std::string &method, const std::string &url, const std::string &headers = "",
                     const std::string &body = "", const std::string &file = "", double timeout = 10) {
        return request(method.data(), method.size(), url.data(), url.size(), headers.data(), headers.size(), body.data(),
            body.size(), file.data(), file.size(), timeout);
    };

    report("hello", start("GET", base + "/hello"));
    report("binary", start("GET", base + "/binary"));
    report("missing", start("GET", base + "/missing"));
    report("redirect", start("GET", base + "/redirect"));
    report("post", start("POST", base + "/echo", "X-Ask: yes\nContent-Type: text/plain", std::string("a=1\0b", 5)));
    report("put", start("PUT", base + "/echo", "", "put body"));
    report("delete", start("DELETE", base + "/echo"));
    report("unreachable", start("GET", "http://127.0.0.1:1/"));
    report("bad-url", start("GET", "notaurl://x"));

    {
        int64_t a = start("GET", base + "/hello"), b = start("GET", base + "/binary"), c = start("GET", base + "/hello");
        report("parallel-a", a);
        report("parallel-b", b);
        report("parallel-c", c);
    }

    auto t = std::chrono::steady_clock::now();
    report("timeout", start("GET", base + "/slow", "", "", "", 1));
    say("check timeout-ms " + std::to_string(millisSince(t)));

    report("big", start("GET", base + "/big"));
    report("download", start("GET", base + "/big", "", "", dir + "/sub dir/big é.bin"));
    report("download-missing", start("GET", base + "/missing", "", "", dir + "/missing.bin"));
    report("download-unwritable", start("GET", base + "/hello", "", "", "/proc/nope/x.bin"));

    {
        int64_t id = start("GET", base + "/slow");
        std::this_thread::sleep_for(std::chrono::milliseconds(300));
        t = std::chrono::steady_clock::now();
        cancel(id);
        say("check cancel-ms " + std::to_string(millisSince(t)));
        report("cancelled", id, 1500);
        cancel(id); // twice: harmless
        cancel(999); // unknown: harmless
    }

    {
        int64_t id = start("GET", base + "/slow");
        std::this_thread::sleep_for(std::chrono::milliseconds(300));
        t = std::chrono::steady_clock::now();
        reset();
        say("check reset-ms " + std::to_string(millisSince(t)));
        report("after-reset", id, 500);
        report("again", start("GET", base + "/hello"));
    }

    {
        std::lock_guard<std::mutex> lock(mutex);
        int after = 0;
        for (auto &[id, o] : outcomes) {
            after += o.afterEnd;
        }
        say("check events-after-end " + std::to_string(after));
    }
    say("check done 1");
    return 0;
}
