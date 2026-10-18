// For native module libraries (modules/<name>/native): the export macro and the event callback
// RageKit hands to rkm_<name>_init(). Stable: module libraries built against it keep working.
#pragma once

#include <stddef.h>
#include <stdint.h>

#if defined(_WIN32)
#  define RKM_API __declspec(dllexport)
#else
#  define RKM_API __attribute__((visibility("default")))
#endif

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Sends an event to PHP (RageKit\Events): callable from any thread, any number of times; the
 * strings are copied before it returns. kind is e.g. "finished", data is free-form text.
 */
typedef void (*rk_post_fn)(const char *module, int64_t handle, const char *kind, const char *data, size_t data_len);

#ifdef __cplusplus
}
#endif
