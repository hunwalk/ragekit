// perform() with libcurl (Linux, macOS).
#ifndef _WIN32

#if !__has_include(<curl/curl.h>)
#error "the http module needs libcurl's headers: apt install libcurl4-openssl-dev (Debian/Ubuntu)"
#endif

#include "transfer.h"

#include <curl/curl.h>

#include <chrono>
#include <cmath>
#include <mutex>

namespace rkhttp {

namespace {

struct Context {
    const Transfer *transfer;
    Result *result;
    bool aborted = false;
};

size_t onHeader(char *data, size_t size, size_t count, void *user)
{
    auto *context = static_cast<Context *>(user);
    std::string line(data, size * count);
    while (!line.empty() && (line.back() == '\n' || line.back() == '\r')) {
        line.pop_back();
    }
    if (line.rfind("HTTP/", 0) == 0) {
        context->result->headers.clear(); // a new response (after a redirect or 100 Continue)
    } else if (!line.empty()) {
        context->result->headers.push_back(line);
    }
    return size * count;
}

size_t onData(char *data, size_t size, size_t count, void *user)
{
    auto *context = static_cast<Context *>(user);
    if (!context->transfer->onData(data, size * count)) {
        context->aborted = true;
        return 0; // curl stops with CURLE_WRITE_ERROR
    }
    return size * count;
}

int onProgress(void *user, curl_off_t total, curl_off_t received, curl_off_t, curl_off_t)
{
    auto *context = static_cast<Context *>(user);
    if (!context->transfer->onProgress(received, total > 0 ? total : -1)) {
        context->aborted = true;
        return 1;
    }
    return 0;
}

} // namespace

Result perform(const Transfer &transfer)
{
    static std::once_flag initialised;
    std::call_once(initialised, [] { curl_global_init(CURL_GLOBAL_DEFAULT); });

    Result result;
    CURL *curl = curl_easy_init();
    if (curl == nullptr) {
        result.error = "can't start libcurl";
        return result;
    }
    Context context{&transfer, &result};
    char message[CURL_ERROR_SIZE] = "";
    long seconds = std::lround(std::ceil(transfer.timeout > 0 ? transfer.timeout : 30));

    curl_easy_setopt(curl, CURLOPT_URL, transfer.url.c_str());
    curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
    curl_easy_setopt(curl, CURLOPT_ERRORBUFFER, message);
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
    curl_easy_setopt(curl, CURLOPT_MAXREDIRS, 10L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, seconds);
    curl_easy_setopt(curl, CURLOPT_LOW_SPEED_LIMIT, 1L); // gives up after `seconds` without a byte
    curl_easy_setopt(curl, CURLOPT_LOW_SPEED_TIME, seconds);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "RageKit");
    curl_easy_setopt(curl, CURLOPT_ACCEPT_ENCODING, ""); // every encoding curl can decode
    curl_easy_setopt(curl, CURLOPT_HEADERFUNCTION, onHeader);
    curl_easy_setopt(curl, CURLOPT_HEADERDATA, &context);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, onData);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &context);
    curl_easy_setopt(curl, CURLOPT_NOPROGRESS, 0L);
    curl_easy_setopt(curl, CURLOPT_XFERINFOFUNCTION, onProgress);
    curl_easy_setopt(curl, CURLOPT_XFERINFODATA, &context);

    if (transfer.method == "HEAD") {
        curl_easy_setopt(curl, CURLOPT_NOBODY, 1L);
    } else if (transfer.method != "GET" || !transfer.body.empty()) {
        if (transfer.method != "POST") {
            curl_easy_setopt(curl, CURLOPT_CUSTOMREQUEST, transfer.method.c_str());
        }
        if (!transfer.body.empty() || transfer.method == "POST") {
            curl_easy_setopt(curl, CURLOPT_POSTFIELDS, transfer.body.data());
            curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE_LARGE, static_cast<curl_off_t>(transfer.body.size()));
        }
    }
    curl_slist *headers = curl_slist_append(nullptr, "Expect:"); // no 100-continue round trip
    for (const auto &header : transfer.headers) {
        headers = curl_slist_append(headers, header.c_str());
    }
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);

    CURLcode code = curl_easy_perform(curl);
    if (context.aborted) {
        result.aborted = true;
    } else if (code == CURLE_OPERATION_TIMEDOUT) {
        result.error = "timed out: nothing arrived for " + std::to_string(seconds) + " s";
    } else if (code != CURLE_OK) {
        result.error = message[0] != '\0' ? message : curl_easy_strerror(code);
        result.headers.clear();
    } else {
        long status = 0;
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &status);
        result.status = static_cast<int>(status);
    }
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    return result;
}

} // namespace rkhttp

#endif
