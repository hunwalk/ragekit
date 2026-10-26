// PHPX side of the Slint backend (C++17, compiled by TypePHP — also in Nano builds). Everything
// Slint- or OS-specific lives in libragekit_slint behind ragekit_slint.h.
#include <phpx.h>

#include "ragekit_slint.h"

#include <string>
#include <vector>

using namespace php;

namespace {

Variant dispatcher;
Variant eventDispatcher;
int64_t listWindow = 0;
int32_t listId = 0;
std::vector<std::string> listItems;

void onEvent(int64_t window, int64_t action, const char *arg, size_t len)
{
    if (dispatcher.isNull()) {
        return;
    }
    Array args;
    args.append(Variant(static_cast<Int>(window)));
    args.append(Variant(static_cast<Int>(action)));
    args.append(Variant(arg, len));
    php::call(dispatcher, args);
}

void onModuleEvent(const char *module, size_t module_len, int64_t handle, const char *kind, size_t kind_len, const char *data, size_t data_len)
{
    if (eventDispatcher.isNull()) {
        return;
    }
    Array args;
    args.append(Variant(module, module_len));
    args.append(Variant(static_cast<Int>(handle)));
    args.append(Variant(kind, kind_len));
    args.append(Variant(data, data_len));
    php::call(eventDispatcher, args);
}

} // namespace

void php_slint_set_dispatcher(Var fn)
{
    // Renderer chosen in project.yml (`ragekit: renderer:`); an explicit SLINT_BACKEND still wins.
#if defined(RAGEKIT_SLINT_RENDERER_SOFTWARE)
    rk_set_renderer("software");
#elif defined(RAGEKIT_SLINT_RENDERER_FEMTOVG)
    rk_set_renderer("femtovg");
#elif defined(RAGEKIT_SLINT_RENDERER_SKIA)
    rk_set_renderer("skia");
#endif
    dispatcher = fn;
    rk_set_dispatcher(onEvent);
}

Int php_slint_open(String screen, String title, Int width, Int height)
{
    return rk_open(screen.data(), screen.length(), title.data(), title.length(),
                   static_cast<int32_t>(width), static_cast<int32_t>(height));
}

void php_slint_set_string(Int window, Int id, String value)
{
    rk_set_string(window, static_cast<int32_t>(id), value.data(), value.length());
}

void php_slint_set_int(Int window, Int id, Int value)
{
    rk_set_int(window, static_cast<int32_t>(id), value);
}

void php_slint_set_float(Int window, Int id, Float value)
{
    rk_set_float(window, static_cast<int32_t>(id), value);
}

void php_slint_set_bool(Int window, Int id, Bool value)
{
    rk_set_bool(window, static_cast<int32_t>(id), value ? 1 : 0);
}

void php_slint_list_begin(Int window, Int id)
{
    listWindow = window;
    listId = static_cast<int32_t>(id);
    listItems.clear();
}

void php_slint_list_append(String item)
{
    listItems.emplace_back(item.data(), item.length());
}

void php_slint_list_end()
{
    std::vector<const char *> items;
    std::vector<size_t> lens;
    for (const auto &item : listItems) {
        items.push_back(item.data());
        lens.push_back(item.size());
    }
    rk_set_list(listWindow, listId, items.data(), lens.data(), items.size());
    listItems.clear();
}

void php_slint_rows_begin(Int window, Int id)
{
    rk_rows_begin(window, static_cast<int32_t>(id));
}

void php_slint_rows_next()
{
    rk_rows_next();
}

void php_slint_rows_string(String value)
{
    rk_rows_string(value.data(), value.length());
}

void php_slint_rows_int(Int value)
{
    rk_rows_int(value);
}

void php_slint_rows_float(Float value)
{
    rk_rows_float(value);
}

void php_slint_rows_bool(Bool value)
{
    rk_rows_bool(value ? 1 : 0);
}

void php_slint_rows_image_asset(Int index)
{
    rk_rows_image_asset(static_cast<int32_t>(index));
}

Bool php_slint_rows_image_file(String path)
{
    return rk_rows_image_file(path.data(), path.length()) != 0;
}

void php_slint_rows_end()
{
    rk_rows_end();
}

void php_slint_close(Int window)
{
    rk_close(window);
}

void php_slint_window_minimize(Int window)
{
    rk_window_minimize(window);
}

void php_slint_window_set_maximized(Int window, Bool maximized)
{
    rk_window_set_maximized(window, maximized ? 1 : 0);
}

void php_slint_timer_start(Int id, Int milliseconds, Bool repeat)
{
    rk_timer_start(id, milliseconds, repeat ? 1 : 0);
}

void php_slint_timer_stop(Int id)
{
    rk_timer_stop(id);
}

// project.yml `ragekit: { links: [a, b] }` arrives as RAGEKIT_LINKS=a__b ('_' isn't valid in a
// scheme, and a comma would split the macro).
#define RK_STRINGIFY2(x) #x
#define RK_STRINGIFY(x) RK_STRINGIFY2(x)

String php_slint_links()
{
#if defined(RAGEKIT_LINKS)
    std::string links = RK_STRINGIFY(RAGEKIT_LINKS);
    std::string out;
    for (size_t i = 0; i < links.size(); i++) {
        if (links.compare(i, 2, "__") == 0) {
            out += ',';
            i++;
        } else {
            out += links[i];
        }
    }
    return String(out.data(), out.size());
#else
    return String("");
#endif
}

Int php_slint_links_claim(String schemes)
{
    int first = rk_links_claim(std::string(schemes.data(), schemes.length()).c_str());
    if (first == 0) {
        // This launch ends without running the event loop: release the PHP closures now, as
        // php_slint_run() would, or they'd be destroyed after PHP has shut down (a crash at exit).
        dispatcher = Variant();
        eventDispatcher = Variant();
    }
    return first;
}

String php_slint_links_register(String scheme)
{
    char error[1024];
    size_t len = rk_links_register(std::string(scheme.data(), scheme.length()).c_str(), error, sizeof error);
    return String(error, len < sizeof error ? len : sizeof error - 1);
}

Int php_slint_utc_offset(Int at)
{
    return rk_utc_offset(at);
}

String php_slint_open_url(String url)
{
    char error[1024];
    size_t len = rk_open_url(std::string(url.data(), url.length()).c_str(), error, sizeof error);
    return String(error, len < sizeof error ? len : sizeof error - 1);
}

void php_slint_window_set_visible(Int window, Bool visible)
{
    rk_window_set_visible(window, visible ? 1 : 0);
}

void php_slint_window_raise(Int window, String title)
{
    rk_window_raise(window, title.data(), title.length());
}

void php_slint_run()
{
    rk_run();
    dispatcher = Variant(); // release the PHP closures before PHP shuts down
    eventDispatcher = Variant();
}

void php_slint_quit()
{
    rk_quit();
}

void php_slint_set_event_dispatcher(Var fn)
{
    eventDispatcher = fn;
    rk_set_event_dispatcher(onModuleEvent);
}

String php_slint_app_dir()
{
    char buffer[4096];
    size_t len = rk_app_dir(buffer, sizeof buffer);
    return String(buffer, len);
}

void php_slint_set_image_asset(Int window, Int id, Int index)
{
    rk_set_image_asset(window, static_cast<int32_t>(id), static_cast<int32_t>(index));
}

Bool php_slint_set_image_file(Int window, Int id, String path)
{
    return rk_set_image_file(window, static_cast<int32_t>(id), path.data(), path.length()) != 0;
}

String php_slint_cache_dir()
{
    char buffer[4096];
    size_t len = rk_cache_dir(buffer, sizeof buffer);
    return String(buffer, len);
}

void php_slint_print_error(String line)
{
    rk_print_error(line.data(), line.length());
}

Bool php_ragekit_module_available(String name)
{
    return rk_module_open(std::string(name.data(), name.length()).c_str()) != 0;
}

String php_ragekit_module_error(String name)
{
    std::string module(name.data(), name.length());
    rk_module_open(module.c_str());
    return String(rk_module_error(module.c_str()));
}
