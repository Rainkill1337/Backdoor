#pragma once
#include "qa.h"

struct z1 { wstring yV; string yI; };

string zL();
string zM(const string& path);
string yT(const string& path);
string zP(const string& path, int commandId);
string zN(const string& path, int commandId);
void zB(const string& localPath, const string& savePath);
void zI(const wstring& dirPath, const wstring& basePath, vector<z1>& entries);
string zA(const string& path, int commandId);
