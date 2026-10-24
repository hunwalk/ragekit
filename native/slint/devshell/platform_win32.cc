#ifdef _WIN32

#include "platform.h"

#define WIN32_LEAN_AND_MEAN
#define NOMINMAX
#include <windows.h>
#include <shellapi.h>

#include <algorithm>
#include <cstdlib>
#include <utility>

namespace rk::dev::platform {

namespace {

std::wstring wide(const std::string &text)
{
    if (text.empty()) {
        return {};
    }
    int size = MultiByteToWideChar(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), nullptr, 0);
    std::wstring out(static_cast<size_t>(size), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, text.data(), static_cast<int>(text.size()), out.data(), size);
    return out;
}

// One argument as CommandLineToArgvW (and the C runtime) splits it back: quotes around it when
// needed, backslashes doubled before a quote.
void quote(std::wstring &line, const std::wstring &arg)
{
    if (!arg.empty() && arg.find_first_of(L" \t\n\v\"") == std::wstring::npos) {
        line += arg;
        return;
    }
    line += L'"';
    size_t backslashes = 0;
    for (wchar_t c : arg) {
        if (c == L'\\') {
            ++backslashes;
            continue;
        }
        line.append(c == L'"' ? backslashes * 2 + 1 : backslashes, L'\\');
        backslashes = 0;
        line += c;
    }
    line.append(backslashes * 2, L'\\');
    line += L'"';
}

} // namespace

bool spawn(const std::vector<std::string> &argv, Handle &process)
{
    std::wstring line;
    for (const auto &arg : argv) {
        if (!line.empty()) {
            line += L' ';
        }
        quote(line, wide(arg));
    }

    // The child inherits stdout/stderr and nothing else (not the shell's sockets). Its stdin is
    // NUL: the shell's stdin is a pipe with a read pending on another thread (the control
    // commands), and Windows serializes synchronous I/O on it, so PHP's start-up queries of an
    // inherited copy would block until `ragekit dev` sends the next command.
    SECURITY_ATTRIBUTES inheritable{sizeof(SECURITY_ATTRIBUTES), nullptr, TRUE};
    HANDLE nul = CreateFileW(L"NUL", GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, &inheritable, OPEN_EXISTING, 0, nullptr);
    STARTUPINFOEXW startup{};
    startup.StartupInfo.cb = sizeof startup;
    startup.StartupInfo.dwFlags = STARTF_USESTDHANDLES;
    startup.StartupInfo.hStdInput = nul;
    startup.StartupInfo.hStdOutput = GetStdHandle(STD_OUTPUT_HANDLE);
    startup.StartupInfo.hStdError = GetStdHandle(STD_ERROR_HANDLE);
    std::vector<HANDLE> inherit;
    for (HANDLE handle : {startup.StartupInfo.hStdInput, startup.StartupInfo.hStdOutput, startup.StartupInfo.hStdError}) {
        if (handle != nullptr && handle != INVALID_HANDLE_VALUE && std::find(inherit.begin(), inherit.end(), handle) == inherit.end()
            && SetHandleInformation(handle, HANDLE_FLAG_INHERIT, HANDLE_FLAG_INHERIT)) {
            inherit.push_back(handle);
        }
    }
    SIZE_T size = 0;
    InitializeProcThreadAttributeList(nullptr, 1, 0, &size);
    std::vector<char> attributes(size);
    startup.lpAttributeList = reinterpret_cast<LPPROC_THREAD_ATTRIBUTE_LIST>(attributes.data());
    BOOL ok = InitializeProcThreadAttributeList(startup.lpAttributeList, 1, 0, &size);
    PROCESS_INFORMATION info{};
    if (ok) {
        if (!inherit.empty()) {
            UpdateProcThreadAttribute(startup.lpAttributeList, 0, PROC_THREAD_ATTRIBUTE_HANDLE_LIST, inherit.data(), inherit.size() * sizeof(HANDLE), nullptr, nullptr);
        }
        ok = CreateProcessW(nullptr, line.data(), nullptr, nullptr, inherit.empty() ? FALSE : TRUE,
                            EXTENDED_STARTUPINFO_PRESENT | CREATE_UNICODE_ENVIRONMENT, nullptr, nullptr, &startup.StartupInfo, &info);
        DeleteProcThreadAttributeList(startup.lpAttributeList);
    }
    if (nul != INVALID_HANDLE_VALUE) {
        CloseHandle(nul);
    }
    if (!ok) {
        return false;
    }
    CloseHandle(info.hThread);
    process = reinterpret_cast<Handle>(info.hProcess);
    return true;
}

bool running(Handle process)
{
    return WaitForSingleObject(reinterpret_cast<HANDLE>(process), 0) == WAIT_TIMEOUT;
}

int wait(Handle process)
{
    auto handle = reinterpret_cast<HANDLE>(process);
    WaitForSingleObject(handle, INFINITE);
    DWORD code = 0;
    GetExitCodeProcess(handle, &code);
    return static_cast<int>(code);
}

void release(Handle process)
{
    CloseHandle(reinterpret_cast<HANDLE>(process));
}

void terminate(Handle process)
{
    // Windows has no SIGTERM for console programs: PHP ends at once, as it would on the signal.
    TerminateProcess(reinterpret_cast<HANDLE>(process), 1);
}

// Both the C runtime's copy (getenv) and the process's own (what Slint and children read).
void setEnv(const std::string &name, const std::string &value)
{
    SetEnvironmentVariableW(wide(name).c_str(), wide(value).c_str());
    _wputenv_s(wide(name).c_str(), wide(value).c_str());
}

void unsetEnv(const std::string &name)
{
    SetEnvironmentVariableW(wide(name).c_str(), nullptr);
    _wputenv_s(wide(name).c_str(), L"");
}

std::vector<std::string> arguments(int argc, char **argv)
{
    int count = 0;
    LPWSTR *args = CommandLineToArgvW(GetCommandLineW(), &count);
    if (args == nullptr) {
        return std::vector<std::string>(argv, argv + argc);
    }
    std::vector<std::string> out;
    for (int i = 0; i < count; ++i) {
        int size = WideCharToMultiByte(CP_UTF8, 0, args[i], -1, nullptr, 0, nullptr, nullptr);
        std::string arg(static_cast<size_t>(size > 0 ? size - 1 : 0), '\0');
        WideCharToMultiByte(CP_UTF8, 0, args[i], -1, arg.data(), size, nullptr, nullptr);
        out.push_back(std::move(arg));
    }
    LocalFree(args);
    return out;
}

} // namespace rk::dev::platform

#endif
