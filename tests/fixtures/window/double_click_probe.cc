// DoubleClickTest: feeds press times/positions to rk::win::DoubleClick, prints 1 per double-click.
#include "double_click.h"

#include <cstdio>

int main()
{
    rk::win::DoubleClick clicks;
    struct Press { long long ms; int x, y; } presses[] = {
        {1000, 10, 10}, {1300, 11, 10}, // double
        {1400, 11, 10},                 // a third press starts over
        {2000, 50, 50}, {2100, 90, 50}, // too far apart
        {3000, 10, 10}, {3600, 10, 10}, // too slow
    };
    for (const auto &p : presses) {
        std::printf("%d", clicks.press(p.ms, p.x, p.y, 500, 4) ? 1 : 0);
    }
    std::printf("\n");
}
