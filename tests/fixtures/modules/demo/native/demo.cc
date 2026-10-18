// The demo test module's library (ModuleEmitterTest builds it with the generated call entry).
#include "rk_module_demo.h"

#include <cmath>
#include <string>

static thread_local std::string echoed;

RKM_API const char *rkm_demo_echo(const char *text, size_t text_len, int64_t times)
{
    if (times < 0) {
        return nullptr; // a misbehaving module: callers must read NULL as ""
    }
    echoed.clear();
    for (int64_t i = 0; i < times; ++i) {
        echoed.append(text, text_len);
    }
    return echoed.c_str();
}

RKM_API double rkm_demo_add(double a, double b, int round)
{
    return round ? std::round((a + b) * 10) / 10 : a + b;
}

RKM_API void rkm_demo_ping(void) { }
RKM_API void rkm_demo_init(rk_post_fn) { }
RKM_API void rkm_demo_reset(void) { }
