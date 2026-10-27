// The dialogs module's library on Windows: the common item dialogs (IFileOpenDialog,
// IFileSaveDialog). Each dialog runs modally on its own thread, owned by the app's active window
// (which Windows disables meanwhile, as for any modal dialog), so the app's event loop keeps
// running; the outcome is posted as a "done" event.
#ifdef _WIN32

#include "rk_module_dialogs.h"

#include <windows.h>
#include <shobjidl.h>

#include <cstdint>
#include <map>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

namespace {

rk_post_fn post = nullptr;
std::mutex lock; // guards everything below
std::map<int64_t, std::thread> running; // dialogs still open (and finished ones not joined yet)
std::vector<int64_t> finished;
int64_t nextId = 1;
bool stopping = false;
std::string lastError;
thread_local std::string result;

const char *answer(std::string text)
{
    result = std::move(text);
    return result.c_str();
}

std::wstring wide(const std::string &utf8)
{
    if (utf8.empty()) {
        return std::wstring();
    }
    int n = MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), nullptr, 0);
    std::wstring out(static_cast<size_t>(n), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), out.data(), n);
    return out;
}

std::string narrow(const wchar_t *text)
{
    int n = WideCharToMultiByte(CP_UTF8, 0, text, -1, nullptr, 0, nullptr, nullptr);
    if (n <= 1) {
        return std::string();
    }
    std::string out(static_cast<size_t>(n - 1), '\0');
    WideCharToMultiByte(CP_UTF8, 0, text, -1, out.data(), n, nullptr, nullptr);
    return out;
}

std::vector<std::string> split(const std::string &text, char by)
{
    std::vector<std::string> parts;
    size_t start = 0;
    while (start <= text.size()) {
        size_t end = text.find(by, start);
        if (end == std::string::npos) {
            end = text.size();
        }
        if (end > start) {
            parts.push_back(text.substr(start, end - start));
        }
        start = end + 1;
    }
    return parts;
}

/** The app's window the dialog belongs to: the active one if it's ours, else any visible one of ours. */
HWND owner()
{
    HWND active = GetForegroundWindow();
    DWORD pid = 0;
    GetWindowThreadProcessId(active, &pid);
    if (active != nullptr && pid == GetCurrentProcessId()) {
        return active;
    }
    HWND found = nullptr;
    EnumWindows([](HWND hwnd, LPARAM out) -> BOOL {
        DWORD p = 0;
        GetWindowThreadProcessId(hwnd, &p);
        if (p == GetCurrentProcessId() && IsWindowVisible(hwnd) && GetWindow(hwnd, GW_OWNER) == nullptr) {
            *reinterpret_cast<HWND *>(out) = hwnd;
            return FALSE;
        }
        return TRUE;
    }, reinterpret_cast<LPARAM>(&found));
    return found;
}

struct Request {
    int64_t id;
    int kind; // 0 open, 1 save, 2 folder
    std::wstring title, name, folder;
    std::vector<std::pair<std::wstring, std::wstring>> filters; // name, "*.png;*.jpg"
    bool multiple;
    HWND parent;
};

std::string pathOf(IShellItem *item)
{
    PWSTR path = nullptr;
    std::string out;
    if (SUCCEEDED(item->GetDisplayName(SIGDN_FILESYSPATH, &path))) {
        out = narrow(path);
        CoTaskMemFree(path);
    }
    return out;
}

/** Shows it and waits; the chosen paths, one per line ('' = cancelled), or false + why. */
bool run(const Request &r, std::string &out)
{
    IFileDialog *dialog = nullptr;
    HRESULT hr = CoCreateInstance(r.kind == 1 ? CLSID_FileSaveDialog : CLSID_FileOpenDialog, nullptr, CLSCTX_INPROC_SERVER,
                                  r.kind == 1 ? IID_IFileSaveDialog : IID_IFileOpenDialog, reinterpret_cast<void **>(&dialog));
    if (FAILED(hr)) {
        out = "couldn't create the file dialog (HRESULT " + std::to_string(static_cast<unsigned long>(hr)) + ")";
        return false;
    }
    FILEOPENDIALOGOPTIONS options = 0;
    dialog->GetOptions(&options);
    options |= FOS_FORCEFILESYSTEM;
    if (r.kind == 2) {
        options |= FOS_PICKFOLDERS;
    } else if (r.kind == 0 && r.multiple) {
        options |= FOS_ALLOWMULTISELECT;
    }
    dialog->SetOptions(options);
    if (!r.title.empty()) {
        dialog->SetTitle(r.title.c_str());
    }
    std::vector<COMDLG_FILTERSPEC> specs;
    for (const auto &f : r.filters) {
        specs.push_back({f.first.c_str(), f.second.c_str()});
    }
    if (!specs.empty() && r.kind != 2) {
        dialog->SetFileTypes(static_cast<UINT>(specs.size()), specs.data());
        if (r.kind == 1) {
            // The first filter's first extension, added when the user types a name without one.
            std::wstring first = r.filters[0].second;
            size_t dot = first.find(L'.');
            size_t end = first.find(L';');
            if (dot != std::wstring::npos) {
                dialog->SetDefaultExtension(first.substr(dot + 1, end == std::wstring::npos ? std::wstring::npos : end - dot - 1).c_str());
            }
        }
    }
    if (r.kind == 1 && !r.name.empty()) {
        dialog->SetFileName(r.name.c_str());
    }
    if (!r.folder.empty()) {
        IShellItem *start = nullptr;
        if (SUCCEEDED(SHCreateItemFromParsingName(r.folder.c_str(), nullptr, IID_PPV_ARGS(&start)))) {
            dialog->SetFolder(start);
            start->Release();
        }
    }
    hr = dialog->Show(r.parent);
    bool ok = true;
    if (hr == HRESULT_FROM_WIN32(ERROR_CANCELLED)) {
        out.clear();
    } else if (FAILED(hr)) {
        out = "the file dialog failed (HRESULT " + std::to_string(static_cast<unsigned long>(hr)) + ")";
        ok = false;
    } else if (r.kind == 0 && r.multiple) {
        IShellItemArray *items = nullptr;
        if (SUCCEEDED(static_cast<IFileOpenDialog *>(dialog)->GetResults(&items))) {
            DWORD n = 0;
            items->GetCount(&n);
            for (DWORD i = 0; i < n; i++) {
                IShellItem *item = nullptr;
                if (SUCCEEDED(items->GetItemAt(i, &item))) {
                    std::string path = pathOf(item);
                    if (!path.empty()) {
                        out += (out.empty() ? "" : "\n") + path;
                    }
                    item->Release();
                }
            }
            items->Release();
        }
    } else {
        IShellItem *item = nullptr;
        if (SUCCEEDED(dialog->GetResult(&item))) {
            out = pathOf(item);
            item->Release();
        }
    }
    dialog->Release();
    return ok;
}

/** Joins the threads of dialogs that have closed; under lock. */
void reap()
{
    for (int64_t id : finished) {
        auto it = running.find(id);
        if (it != running.end()) {
            it->second.join();
            running.erase(it);
        }
    }
    finished.clear();
}

void shutdown()
{
    std::map<int64_t, std::thread> open;
    {
        std::lock_guard<std::mutex> guard(lock);
        stopping = true;
        open.swap(running);
        finished.clear();
    }
    for (auto &entry : open) {
        // Closes the dialog's windows, which ends its Show() as cancelled.
        EnumThreadWindows(GetThreadId(entry.second.native_handle()), [](HWND hwnd, LPARAM) -> BOOL {
            PostMessageW(hwnd, WM_CLOSE, 0, 0);
            return TRUE;
        }, 0);
        entry.second.join();
    }
    std::lock_guard<std::mutex> guard(lock);
    stopping = false;
}

struct ShutdownGuard {
    ~ShutdownGuard() { shutdown(); } // a thread still running at exit would abort the process
} shutdownGuard;

} // namespace

extern "C" {

RKM_API void rkm_dialogs_init(rk_post_fn fn)
{
    post = fn;
}

RKM_API void rkm_dialogs_reset(void)
{
    shutdown();
}

RKM_API const char *rkm_dialogs_available(void)
{
    return answer("");
}

RKM_API int64_t rkm_dialogs_file(int64_t kind, const char *title, size_t title_len, const char *filters, size_t filters_len,
                                 int multiple, const char *file, size_t file_len, const char *folder, size_t folder_len)
{
    Request r;
    r.kind = static_cast<int>(kind);
    r.title = wide(std::string(title, title_len));
    r.name = wide(std::string(file, file_len));
    std::wstring start = wide(std::string(folder, folder_len));
    for (wchar_t &c : start) {
        c = c == L'/' ? L'\\' : c; // SHCreateItemFromParsingName wants backslashes
    }
    r.folder = start;
    r.multiple = multiple != 0;
    for (const std::string &line : split(std::string(filters, filters_len), '\n')) {
        size_t tab = line.find('\t');
        std::wstring pattern;
        for (const std::string &ext : split(tab == std::string::npos ? "" : line.substr(tab + 1), ' ')) {
            pattern += (pattern.empty() ? L"" : L";") + (ext == "*" ? std::wstring(L"*.*") : L"*." + wide(ext));
        }
        r.filters.push_back({wide(line.substr(0, tab)), pattern.empty() ? L"*.*" : pattern});
    }
    r.parent = owner();
    std::lock_guard<std::mutex> guard(lock);
    lastError.clear();
    reap();
    r.id = nextId++;
    running.emplace(r.id, std::thread([r] {
        // The dialogs are COM objects that want an apartment-threaded thread of their own.
        CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED | COINIT_DISABLE_OLE1DDE);
        std::string out;
        bool ok = run(r, out);
        CoUninitialize();
        std::lock_guard<std::mutex> guard(lock);
        if (stopping) {
            return; // shutdown() joins this thread; nobody wants the answer
        }
        finished.push_back(r.id);
        if (post != nullptr) {
            post("dialogs", r.id, ok ? "done" : "error", out.c_str(), out.size());
        }
    }));
    return r.id;
}

RKM_API const char *rkm_dialogs_error(void)
{
    std::lock_guard<std::mutex> guard(lock);
    return answer(lastError);
}

} // extern "C"

#endif // _WIN32
