// perform() with WinHTTP (Windows): part of Windows, so nothing to ship.
#ifdef _WIN32

#include "transfer.h"

#include <windows.h>
#include <winhttp.h>

#include <atomic>
#include <cmath>
#include <string>
#include <vector>

namespace rkhttp {

namespace {

std::wstring wide(const std::string &text)
{
    int n = MultiByteToWideChar(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), nullptr, 0);
    std::wstring out(n, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), out.data(), n);
    return out;
}

std::string narrow(const std::wstring &text)
{
    int n = WideCharToMultiByte(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), nullptr, 0, nullptr, nullptr);
    std::string out(n, '\0');
    WideCharToMultiByte(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), out.data(), n, nullptr, nullptr);
    return out;
}

std::string describe(DWORD code)
{
    switch (code) {
    case ERROR_WINHTTP_TIMEOUT: return "timed out";
    case ERROR_WINHTTP_NAME_NOT_RESOLVED: return "could not resolve host";
    case ERROR_WINHTTP_CANNOT_CONNECT: return "could not connect to server";
    case ERROR_WINHTTP_CONNECTION_ERROR: return "the connection was reset";
    case ERROR_WINHTTP_UNRECOGNIZED_SCHEME: return "unsupported URL scheme (http and https only)";
    case ERROR_WINHTTP_INVALID_URL: return "invalid URL";
    case ERROR_WINHTTP_SECURE_FAILURE: return "TLS error (certificate not trusted?)";
    case ERROR_WINHTTP_REDIRECT_FAILED: return "too many redirects";
    default: break;
    }
    wchar_t *message = nullptr;
    HMODULE winhttp = GetModuleHandleW(L"winhttp.dll");
    FormatMessageW(FORMAT_MESSAGE_ALLOCATE_BUFFER | FORMAT_MESSAGE_FROM_SYSTEM | FORMAT_MESSAGE_FROM_HMODULE
            | FORMAT_MESSAGE_IGNORE_INSERTS,
        winhttp, code, 0, reinterpret_cast<wchar_t *>(&message), 0, nullptr);
    std::string text = message != nullptr ? narrow(message) : "WinHTTP error " + std::to_string(code);
    if (message != nullptr) {
        LocalFree(message);
    }
    while (!text.empty() && (text.back() == '\n' || text.back() == '\r' || text.back() == '.')) {
        text.pop_back();
    }
    return text;
}

struct Handle {
    HINTERNET h = nullptr;
    ~Handle()
    {
        if (h != nullptr) {
            WinHttpCloseHandle(h);
        }
    }
};

} // namespace

Result perform(const Transfer &transfer)
{
    Result result;
    auto fail = [&result](DWORD code) {
        result.error = describe(code);
        result.headers.clear();
        return result;
    };

    std::wstring url = wide(transfer.url);
    URL_COMPONENTS parts{};
    parts.dwStructSize = sizeof parts;
    parts.dwHostNameLength = static_cast<DWORD>(-1);
    parts.dwUrlPathLength = static_cast<DWORD>(-1);
    parts.dwExtraInfoLength = static_cast<DWORD>(-1);
    if (!WinHttpCrackUrl(url.c_str(), 0, 0, &parts)) {
        return fail(GetLastError());
    }
    if (parts.nScheme != INTERNET_SCHEME_HTTP && parts.nScheme != INTERNET_SCHEME_HTTPS) {
        return fail(ERROR_WINHTTP_UNRECOGNIZED_SCHEME);
    }
    std::wstring host(parts.lpszHostName, parts.dwHostNameLength);
    std::wstring path(parts.lpszUrlPath, parts.dwUrlPathLength);
    path += std::wstring(parts.lpszExtraInfo, parts.dwExtraInfoLength);
    if (path.empty()) {
        path = L"/";
    }

    Handle session{WinHttpOpen(L"RageKit", WINHTTP_ACCESS_TYPE_AUTOMATIC_PROXY, WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0)};
    if (session.h == nullptr) {
        return fail(GetLastError());
    }
    // Every phase gives up after `timeout` without progress; receive is per read, not total.
    int ms = static_cast<int>(std::lround((transfer.timeout > 0 ? transfer.timeout : 30) * 1000));
    WinHttpSetTimeouts(session.h, ms, ms, ms, ms);
    DWORD decompress = WINHTTP_DECOMPRESSION_FLAG_ALL;
    WinHttpSetOption(session.h, WINHTTP_OPTION_DECOMPRESSION, &decompress, sizeof decompress); // Windows 8.1+

    Handle connection{WinHttpConnect(session.h, host.c_str(), parts.nPort, 0)};
    if (connection.h == nullptr) {
        return fail(GetLastError());
    }
    std::wstring method = wide(transfer.method);
    Handle request{WinHttpOpenRequest(connection.h, method.c_str(), path.c_str(), nullptr, WINHTTP_NO_REFERER,
        WINHTTP_DEFAULT_ACCEPT_TYPES, parts.nScheme == INTERNET_SCHEME_HTTPS ? WINHTTP_FLAG_SECURE : 0)};
    if (request.h == nullptr) {
        return fail(GetLastError());
    }
    // A cancel closes the request handle from the cancelling thread, which makes the blocking call
    // in progress here fail right away (ERROR_WINHTTP_OPERATION_CANCELLED) instead of waiting for
    // the timeout. `request` is then left alone: it was closed already.
    std::atomic<bool> aborted{false};
    if (transfer.setAbort) {
        transfer.setAbort([&aborted, &request] {
            if (!aborted.exchange(true)) {
                WinHttpCloseHandle(request.h);
            }
        });
    }
    struct Unregister {
        const Transfer &transfer;
        std::atomic<bool> &aborted;
        Handle &request;
        ~Unregister()
        {
            if (transfer.setAbort) {
                transfer.setAbort(nullptr); // after this no abort can run
            }
            if (aborted) {
                request.h = nullptr; // closed by the abort
            }
        }
    } unregister{transfer, aborted, request};
    auto failOrAbort = [&](DWORD code) {
        if (aborted) {
            result.aborted = true;
            return result;
        }
        return fail(code);
    };
    DWORD redirects = 10;
    WinHttpSetOption(request.h, WINHTTP_OPTION_MAX_HTTP_AUTOMATIC_REDIRECTS, &redirects, sizeof redirects);

    std::wstring headers;
    for (const auto &header : transfer.headers) {
        headers += wide(header) + L"\r\n";
    }
    if (!WinHttpSendRequest(request.h, headers.empty() ? WINHTTP_NO_ADDITIONAL_HEADERS : headers.c_str(),
            headers.empty() ? 0 : static_cast<DWORD>(-1L),
            transfer.body.empty() ? WINHTTP_NO_REQUEST_DATA : const_cast<char *>(transfer.body.data()),
            static_cast<DWORD>(transfer.body.size()), static_cast<DWORD>(transfer.body.size()), 0)
        || !WinHttpReceiveResponse(request.h, nullptr)) {
        return failOrAbort(GetLastError());
    }

    DWORD status = 0;
    DWORD size = sizeof status;
    WinHttpQueryHeaders(request.h, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER, WINHTTP_HEADER_NAME_BY_INDEX,
        &status, &size, WINHTTP_NO_HEADER_INDEX);
    result.status = static_cast<int>(status);

    size = 0;
    WinHttpQueryHeaders(request.h, WINHTTP_QUERY_RAW_HEADERS_CRLF, WINHTTP_HEADER_NAME_BY_INDEX, nullptr, &size, WINHTTP_NO_HEADER_INDEX);
    if (size > 0) {
        std::wstring raw(size / sizeof(wchar_t), L'\0');
        if (WinHttpQueryHeaders(request.h, WINHTTP_QUERY_RAW_HEADERS_CRLF, WINHTTP_HEADER_NAME_BY_INDEX, raw.data(), &size,
                WINHTTP_NO_HEADER_INDEX)) {
            std::string text = narrow(raw.substr(0, size / sizeof(wchar_t)));
            size_t start = text.find("\r\n"); // skip the status line
            while (start != std::string::npos && start + 2 < text.size()) {
                size_t end = text.find("\r\n", start + 2);
                std::string line = text.substr(start + 2, end == std::string::npos ? std::string::npos : end - start - 2);
                if (!line.empty()) {
                    result.headers.push_back(line);
                }
                start = end;
            }
        }
    }

    int64_t total = -1;
    wchar_t length[32];
    size = sizeof length;
    if (WinHttpQueryHeaders(request.h, WINHTTP_QUERY_CONTENT_LENGTH, WINHTTP_HEADER_NAME_BY_INDEX, length, &size,
            WINHTTP_NO_HEADER_INDEX)) {
        total = _wtoi64(length);
    }

    int64_t received = 0;
    std::vector<char> buffer(64 * 1024);
    for (;;) {
        if (!transfer.onProgress(received, total)) {
            result.aborted = true;
            return result;
        }
        DWORD read = 0;
        if (!WinHttpReadData(request.h, buffer.data(), static_cast<DWORD>(buffer.size()), &read)) {
            return failOrAbort(GetLastError());
        }
        if (read == 0) {
            break;
        }
        received += read;
        if (!transfer.onData(buffer.data(), read)) {
            result.aborted = true;
            return result;
        }
    }
    transfer.onProgress(received, total);
    return result;
}

} // namespace rkhttp

#endif
