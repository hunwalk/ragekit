// Windows: Credential Manager, generic credentials named "<service>:<account>", the secret as the
// credential's blob (raw bytes, at most 2560). Needs an interactive logon: over SSH with a key
// Windows answers "the logon session doesn't exist" (ERROR_NO_SUCH_LOGON_SESSION).
#ifdef _WIN32

#include "store.h"

#include <windows.h>
#include <wincred.h>

#include <string>

namespace rk::keyring {

namespace {

std::wstring wide(const std::string &utf8)
{
    if (utf8.empty()) {
        return std::wstring();
    }
    int len = MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), nullptr, 0);
    std::wstring out(static_cast<size_t>(len), L'\0');
    MultiByteToWideChar(CP_UTF8, 0, utf8.data(), static_cast<int>(utf8.size()), out.data(), len);
    return out;
}

std::wstring target(const std::string &service, const std::string &account)
{
    return wide(service + ":" + account);
}

// Windows' message in the user's language, as UTF-8 (FormatMessageA would give the ANSI code page).
std::string failure(const char *what, DWORD code)
{
    wchar_t *text = nullptr;
    FormatMessageW(FORMAT_MESSAGE_ALLOCATE_BUFFER | FORMAT_MESSAGE_FROM_SYSTEM | FORMAT_MESSAGE_IGNORE_INSERTS, nullptr, code, 0,
                   reinterpret_cast<wchar_t *>(&text), 0, nullptr);
    std::string why = "Windows error " + std::to_string(code);
    if (text != nullptr) {
        int len = WideCharToMultiByte(CP_UTF8, 0, text, -1, nullptr, 0, nullptr, nullptr);
        if (len > 1) {
            why.assign(static_cast<size_t>(len), '\0');
            WideCharToMultiByte(CP_UTF8, 0, text, -1, why.data(), len, nullptr, nullptr);
            why.resize(static_cast<size_t>(len - 1));
        }
        LocalFree(text);
    }
    std::string message = std::string(what) + ": " + why;
    while (!message.empty() && (message.back() == '\n' || message.back() == '\r' || message.back() == ' ' || message.back() == '.')) {
        message.pop_back();
    }
    return message;
}

} // namespace

std::string available()
{
    return "";
}

std::string set(const std::string &service, const std::string &account, const std::string &secret)
{
    if (secret.size() > CRED_MAX_CREDENTIAL_BLOB_SIZE) {
        return "Credential Manager keeps at most " + std::to_string(CRED_MAX_CREDENTIAL_BLOB_SIZE) + " bytes";
    }
    std::wstring name = target(service, account);
    std::wstring user = wide(account);
    std::string blob = secret;
    CREDENTIALW credential = {};
    credential.Type = CRED_TYPE_GENERIC;
    credential.TargetName = name.data();
    credential.UserName = user.data();
    credential.CredentialBlobSize = static_cast<DWORD>(blob.size());
    credential.CredentialBlob = reinterpret_cast<LPBYTE>(blob.data());
    credential.Persist = CRED_PERSIST_LOCAL_MACHINE;
    BOOL ok = CredWriteW(&credential, 0);
    SecureZeroMemory(blob.data(), blob.size());
    return ok ? "" : failure("Credential Manager didn't store the secret", GetLastError());
}

Found get(const std::string &service, const std::string &account)
{
    Found found;
    PCREDENTIALW credential = nullptr;
    if (!CredReadW(target(service, account).c_str(), CRED_TYPE_GENERIC, 0, &credential)) {
        DWORD code = GetLastError();
        if (code != ERROR_NOT_FOUND) {
            found.error = failure("Credential Manager failed", code);
        }
        return found;
    }
    found.found = true;
    if (credential->CredentialBlob != nullptr && credential->CredentialBlobSize > 0) {
        found.secret.assign(reinterpret_cast<const char *>(credential->CredentialBlob), credential->CredentialBlobSize);
        SecureZeroMemory(credential->CredentialBlob, credential->CredentialBlobSize);
    }
    CredFree(credential);
    return found;
}

std::string remove(const std::string &service, const std::string &account)
{
    if (!CredDeleteW(target(service, account).c_str(), CRED_TYPE_GENERIC, 0)) {
        DWORD code = GetLastError();
        return code == ERROR_NOT_FOUND ? "" : failure("Credential Manager didn't delete the secret", code);
    }
    return "";
}

} // namespace rk::keyring

#endif
