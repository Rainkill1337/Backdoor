#pragma once
#include <iostream>
#include <string>
#include <thread>
#include <chrono>
#include <vector>
#include <algorithm>
#include <sstream>
#include <filesystem>
#include <winsock2.h>
#include <windows.h>
#include <wininet.h>
#include <shlobj.h>
#include <fstream>
#include <gdiplus.h>
#include <cstdio>
#include <wincrypt.h>

#pragma comment(lib, "wininet.lib")
#pragma comment(lib, "crypt32.lib")
#pragma comment(lib, "ws2_32.lib")
#pragma comment(lib, "shell32.lib")
#pragma comment(lib, "advapi32.lib")
#pragma comment(lib, "gdiplus.lib")

using namespace std;
using namespace Gdiplus;

extern string yL;
extern int yM;
extern string yK;
extern bool yJ;
extern const wchar_t* yR;
extern string yN;
extern wstring yO;
extern ULONG_PTR yP;

const int yS = 524288;
