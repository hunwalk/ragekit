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
/** A list cell (an array member): the strings / image calls between these are its items. */
RK_API void rk_rows_list_begin(void);
RK_API void rk_rows_list_end(void);
RK_API void rk_rows_end(void);
RK_API void rk_close(int64_t window);
/** Window controls for layouts (frameless windows); see native/slint/window. */
RK_API void rk_window_minimize(int64_t window);
RK_API int rk_window_is_minimized(int64_t window);
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
/* App links (rk_links.cc), for apps that declare schemes (project.yml `ragekit: { links: [...] }`). */
/**
 * One instance per app: hands this launch's link (or "come to the front") to a running instance and
 * returns 0 (the caller exits), or becomes that instance and returns 1. schemes: "a,b". Incoming
 * links arrive as the event "@links" / 0 / "open" (data: the URL) or "focus"; a link this launch
 * was started with arrives the same way.
 */
RK_API int rk_links_claim(const char *schemes);
/** Stops listening for other launches (rk_run does it when the event loop ends). */
RK_API void rk_links_stop(void);
/** Makes the app the OS's handler for `scheme:` links. Returns the error's length (0 = done). */
RK_API size_t rk_links_register(const char *scheme, char *error, size_t size);
/** Seconds east of UTC of the computer's local time at unix time `at` (daylight saving included). */
RK_API int64_t rk_utc_offset(int64_t at);
/** Opens url in the user's browser (Linux: xdg-open; Windows: ShellExecute). Returns the error's length. */
RK_API size_t rk_open_url(const char *url, char *error, size_t size);
/** Hides a window without closing it (a tray app's main window), or shows it again. */
RK_API void rk_window_set_visible(int64_t window, int visible);
/** Brings a window to the front (X11: asks the window manager; Windows: SetForegroundWindow). */
RK_API void rk_window_raise(int64_t window, const char *title, size_t title_len);
/** Puts text on the clipboard (through an open window; nothing without one). */
RK_API void rk_clipboard_set(const char *text, size_t len);
/** The clipboard's text ('' if none, not text, or no window is open); valid until the next call. */
RK_API const char *rk_clipboard_get(size_t *len);
/**
 * The clipboard's image saved as a file in `folder` (pasted-<hash>.<ext>); its path, or '' when the
 * clipboard holds no image. Valid until the next call.
 */
RK_API const char *rk_clipboard_image(const char *folder, size_t folder_len, size_t *len);
/** Whether the clipboard holds an image (1) or not (0). */
RK_API int rk_clipboard_has_image(void);
RK_API void rk_run(void);
RK_API void rk_quit(void);

#ifdef __cplusplus
}
#endif
