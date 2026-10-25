// C ABI of libragekit_slint, the Slint bridge. Plain C so the TypePHP-compiled host can stay
// C++17 (TypePHP Nano requires it); the bridge itself is C++20 and the only code using Slint.
#pragma once

#include <stddef.h>
#include <stdint.h>

#if defined(_WIN32)
#  if defined(RK_BUILDING_BRIDGE)
#    define RK_API __declspec(dllexport)
#  else
#    define RK_API __declspec(dllimport)
#  endif
#else
#  define RK_API __attribute__((visibility("default")))
#endif

#ifdef __cplusplus
extern "C" {
#endif

/** Every UI event: action > 0 runs a screen action with arg; action 0 = the window asks to close. */
typedef void (*rk_event_fn)(int64_t window, int64_t action, const char *arg, size_t arg_len);

RK_API void rk_set_dispatcher(rk_event_fn fn);
/** "software", "femtovg" or "skia"; applied unless SLINT_BACKEND is already set. */
RK_API void rk_set_renderer(const char *renderer);
/** Opens a compiled screen; returns its window id, or 0 if no screen has that name. */
RK_API int64_t rk_open(const char *screen, size_t screen_len, const char *title, size_t title_len, int32_t width, int32_t height);
RK_API void rk_set_string(int64_t window, int32_t id, const char *value, size_t len);
RK_API void rk_set_int(int64_t window, int32_t id, int64_t value);
RK_API void rk_set_float(int64_t window, int32_t id, double value);
RK_API void rk_set_bool(int64_t window, int32_t id, int value);
RK_API void rk_set_list(int64_t window, int32_t id, const char *const *items, const size_t *lens, size_t count);
/** Shows registered image `index` (App::assets(), embedded) in image property `id`. */
RK_API void rk_set_image_asset(int64_t window, int32_t id, int32_t index);
/** Loads the image file at path (UTF-8) into image property `id`; len 0 clears it. 0 = couldn't load it. */
RK_API int rk_set_image_file(int64_t window, int32_t id, const char *path, size_t len);
/* Rows of a collection (or a list shown as images) into property `id`: begin, then per row next()
 * and one call per cell in field order, then end. An image cell is a registered image or a file
 * (len 0 = none); rk_rows_image_file returns 0 if the file couldn't be loaded. */
RK_API void rk_rows_begin(int64_t window, int32_t id);
RK_API void rk_rows_next(void);
RK_API void rk_rows_string(const char *value, size_t len);
RK_API void rk_rows_int(int64_t value);
RK_API void rk_rows_float(double value);
RK_API void rk_rows_bool(int value);
RK_API void rk_rows_image_asset(int32_t index);
RK_API int rk_rows_image_file(const char *path, size_t len);
RK_API void rk_rows_end(void);
RK_API void rk_close(int64_t window);
/** Window controls for layouts (frameless windows); see native/slint/window. */
RK_API void rk_window_minimize(int64_t window);
RK_API void rk_window_set_maximized(int64_t window, int maximized);

/* Native modules (modules/<name>): loaded at runtime, never linked (rk_modules.cc). */

/**
 * The functions the app was built against (newline-separated symbols): a library lacking any of
 * them counts as unavailable. Generated module hosts call this during static initialisation.
 */
RK_API void rk_module_expect(const char *name, const char *symbols);
/** Loads lib rk_module_<name> from the executable's folder (or $RAGEKIT_MODULE_DIR) once; 0 on failure. */
RK_API int64_t rk_module_open(const char *name);
/** Why the module couldn't be loaded ("" if it could, or wasn't tried). */
RK_API const char *rk_module_error(const char *name);
/** A function of a loaded module, or NULL. */
RK_API void *rk_module_symbol(int64_t module, const char *symbol);
/** Calls rkm_<name>_reset() of every loaded module (rk_run does it when the event loop ends). */
RK_API void rk_modules_reset(void);
/** The executable's folder (no trailing slash) into buffer; returns its length. */
RK_API size_t rk_app_dir(char *buffer, size_t size);
/** The app's image cache folder (created; no trailing slash) into buffer; returns its length:
 *  <XDG_CACHE_HOME or ~/.cache | %LOCALAPPDATA% | ~/Library/Caches>/<executable name>/images. */
RK_API size_t rk_cache_dir(char *buffer, size_t size);
/** Writes text and a newline to stderr. */
RK_API void rk_print_error(const char *text, size_t len);

/** A module event for PHP: module, handle, kind, data. */
typedef void (*rk_module_event_fn)(const char *module, size_t module_len, int64_t handle, const char *kind, size_t kind_len, const char *data, size_t data_len);
RK_API void rk_set_event_dispatcher(rk_module_event_fn fn);
/** Thread-safe: queues a module event for the UI thread (matches ragekit_module.h's rk_post_fn). */
RK_API void rk_post_event(const char *module, int64_t handle, const char *kind, const char *data, size_t data_len);
/**
 * Timers (RageKit\Timer): timer `id` fires after `milliseconds`, again and again if `repeat`, as the
 * module event "@timer" / id / "tick" on the UI thread. Starting an id that runs restarts it.
 */
RK_API void rk_timer_start(int64_t id, int64_t milliseconds, int repeat);
RK_API void rk_timer_stop(int64_t id);
RK_API void rk_run(void);
RK_API void rk_quit(void);

#ifdef __cplusplus
}
#endif
