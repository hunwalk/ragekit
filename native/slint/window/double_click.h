// Double-clicks on a drag area, found from presses alone: once the OS or window manager runs its
// move loop, it eats the button releases, so Slint's double-clicked never fires there.
#pragma once

#include <cstdlib>

namespace rk::win {

class DoubleClick {
public:
    /**
     * A press at time ms (milliseconds) and screen position x,y. True when it completes a
     * double-click: within limitMs of the previous press and at most slop pixels away. The press
     * after a double-click starts over.
     */
    bool press(long long ms, int x, int y, long long limitMs, int slop)
    {
        bool doubled = pending_ && ms - lastMs_ <= limitMs && std::abs(x - lastX_) <= slop && std::abs(y - lastY_) <= slop;
        pending_ = !doubled;
        lastMs_ = ms;
        lastX_ = x;
        lastY_ = y;
        return doubled;
    }

private:
    bool pending_ = false;
    long long lastMs_ = 0;
    int lastX_ = 0;
    int lastY_ = 0;
};

} // namespace rk::win
