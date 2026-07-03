#pragma once
#include "qa.h"

class zE {
    HANDLE hRead, hWrite;
    PROCESS_INFORMATION pi;
    int seq;
    bool alive;
public:
    zE();
    ~zE();
    bool Start();
    string Execute(const string& cmd);
};

extern zE yQ;
string yU(const string& cmd);
