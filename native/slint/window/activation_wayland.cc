// Wayland: a window comes forward only with an activation token (xdg-activation-v1). The launch the
// user started gets one from whoever started it (XDG_ACTIVATION_TOKEN: the browser, gio, the
// desktop) and hands it over with its link; raise() activates the window with it. Without one the
// window asks for its own token, which desktops honour at most by marking it as wanting attention
// (GNOME: "… is ready"). libwayland-client is loaded at runtime (Slint's winit loads it too), and
// everything runs on a private event queue, so winit's own queue isn't touched.
#if !defined(_WIN32) && !defined(__APPLE__)

#include "window.h"

#include <cstdint>
#include <cstring>
#include <dlfcn.h>
#include <mutex>
#include <string>
#include <type_traits>

// The few types of libwayland-client's API this file uses, declared here so building needs no
// libwayland-dev (the layouts are part of its stable ABI; wayland-util.h / wayland-client-core.h).
struct wl_proxy;
struct wl_event_queue;
struct wl_display;
struct wl_surface;
struct wl_interface;
struct wl_message {
    const char *name;
    const char *signature;
    const wl_interface **types;
};
struct wl_interface {
    const char *name;
    int version;
    int method_count;
    const wl_message *methods;
    int event_count;
    const wl_message *events;
};
constexpr uint32_t WL_MARSHAL_FLAG_DESTROY = 1 << 0;

namespace rk::win {

namespace {

std::mutex tokenLock;
std::string handedToken;

struct Wl {
    wl_proxy *(*marshal_flags)(wl_proxy *, uint32_t, const wl_interface *, uint32_t, uint32_t, ...);
    int (*add_listener)(wl_proxy *, void (**)(void), void *);
    uint32_t (*get_version)(wl_proxy *);
    wl_event_queue *(*create_queue)(wl_display *);
    void *(*create_wrapper)(void *);
    void (*set_queue)(wl_proxy *, wl_event_queue *);
    int (*roundtrip_queue)(wl_display *, wl_event_queue *);
    int (*flush)(wl_display *);
    const wl_interface *registry;
    const wl_interface *surface;
};

// What wayland-scanner generates for xdg-activation-v1 (staging), filled in once the library is loaded.
const wl_interface *tokenTypes[] = {nullptr, nullptr};
const wl_interface *activationTypes[] = {nullptr, nullptr, nullptr};
const wl_message tokenRequests[] = {
    {"set_serial", "uo", tokenTypes}, {"set_app_id", "s", tokenTypes}, {"set_surface", "o", tokenTypes + 1},
    {"commit", "", tokenTypes}, {"destroy", "", tokenTypes},
};
const wl_message tokenEvents[] = {{"done", "s", tokenTypes}};
const wl_interface tokenInterface = {"xdg_activation_token_v1", 1, 5, tokenRequests, 1, tokenEvents};
const wl_message activationRequests[] = {
    {"destroy", "", activationTypes}, {"get_activation_token", "n", activationTypes}, {"activate", "so", activationTypes + 1},
};
const wl_interface activationInterface = {"xdg_activation_v1", 1, 3, activationRequests, 0, nullptr};

Wl *library()
{
    static Wl wl{};
    static bool tried = false;
    static bool ok = false;
    if (tried) {
        return ok ? &wl : nullptr;
    }
    tried = true;
    void *handle = dlopen("libwayland-client.so.0", RTLD_NOW | RTLD_LOCAL);
    if (handle == nullptr) {
        return nullptr;
    }
    auto load = [handle](auto &field, const char *name) { field = reinterpret_cast<std::remove_reference_t<decltype(field)>>(dlsym(handle, name)); return field != nullptr; };
    ok = load(wl.marshal_flags, "wl_proxy_marshal_flags") && load(wl.add_listener, "wl_proxy_add_listener")
        && load(wl.get_version, "wl_proxy_get_version") && load(wl.create_queue, "wl_display_create_queue")
        && load(wl.create_wrapper, "wl_proxy_create_wrapper") && load(wl.set_queue, "wl_proxy_set_queue") && load(wl.roundtrip_queue, "wl_display_roundtrip_queue")
        && load(wl.flush, "wl_display_flush") && load(wl.registry, "wl_registry_interface") && load(wl.surface, "wl_surface_interface");
    if (ok) {
        tokenTypes[1] = wl.surface;
        activationTypes[0] = &tokenInterface;
        activationTypes[2] = wl.surface;
    }
    return ok ? &wl : nullptr;
}

/** The compositor's xdg_activation_v1, bound once on a private queue (nullptr: not offered). */
struct Bound {
    wl_display *display = nullptr;
    wl_event_queue *queue = nullptr;
    wl_proxy *activation = nullptr;
};

Bound &bind(Wl *wl, wl_display *display)
{
    static Bound bound;
    if (bound.display == display) {
        return bound;
    }
    bound = Bound{display, wl->create_queue(display), nullptr};
    auto *wrapper = static_cast<wl_proxy *>(wl->create_wrapper(display));
    if (wrapper == nullptr) {
        return bound;
    }
    wl->set_queue(wrapper, bound.queue);
    wl_proxy *registry = wl->marshal_flags(wrapper, 1 /* get_registry */, wl->registry, wl->get_version(wrapper), 0, nullptr);
    // Static: the registry stays (and may announce more globals on later roundtrips of this queue).
    struct Context {
        Wl *wl;
        Bound *bound;
    };
    static Context context;
    context = Context{wl, &bound};
    static void (*listener[2])(void) = {
        reinterpret_cast<void (*)(void)>(+[](void *data, wl_proxy *registry, uint32_t name, const char *interface, uint32_t) {
            auto *c = static_cast<Context *>(data);
            if (std::strcmp(interface, "xdg_activation_v1") == 0 && c->bound->activation == nullptr) {
                c->bound->activation = c->wl->marshal_flags(registry, 0 /* bind */, &activationInterface, 1, 0, name, activationInterface.name, 1u, nullptr);
            }
        }),
        reinterpret_cast<void (*)(void)>(+[](void *, wl_proxy *, uint32_t) {}),
    };
    wl->add_listener(registry, listener, &context);
    wl->roundtrip_queue(display, bound.queue);
    return bound;
}

/** A token of the window's own, for when no launch handed one over ('' if none came). */
std::string ownToken(Wl *wl, Bound &bound, wl_surface *surface)
{
    std::string token;
    wl_proxy *request = wl->marshal_flags(bound.activation, 1 /* get_activation_token */, &tokenInterface, 1, 0, nullptr);
    if (request == nullptr) {
        return token;
    }
    static void (*listener[1])(void) = {
        reinterpret_cast<void (*)(void)>(+[](void *data, wl_proxy *, const char *value) { *static_cast<std::string *>(data) = value; }),
    };
    wl->add_listener(request, listener, &token);
    wl->marshal_flags(request, 2 /* set_surface */, nullptr, 1, 0, surface);
    wl->marshal_flags(request, 3 /* commit */, nullptr, 1, 0);
    for (int i = 0; i < 5 && token.empty(); i++) {
        if (wl->roundtrip_queue(bound.display, bound.queue) < 0) {
            break;
        }
    }
    wl->marshal_flags(request, 4 /* destroy */, nullptr, 1, WL_MARSHAL_FLAG_DESTROY);
    return token;
}

} // namespace

void set_activation_token(const std::string &token)
{
    std::lock_guard<std::mutex> guard(tokenLock);
    handedToken = token;
}

std::string take_activation_token()
{
    std::lock_guard<std::mutex> guard(tokenLock);
    std::string token;
    token.swap(handedToken);
    return token;
}

bool activate_wayland(slint::Window &window)
{
    wl_surface *surface = window.wayland_surface();
    wl_display *display = window.wayland_display();
    if (surface == nullptr || display == nullptr) {
        return false; // X11 (or not shown yet)
    }
    Wl *wl = library();
    if (wl == nullptr) {
        return true;
    }
    Bound &bound = bind(wl, display);
    if (bound.activation == nullptr) {
        return true; // the compositor doesn't offer xdg-activation
    }
    std::string token = take_activation_token();
    if (token.empty()) {
        token = ownToken(wl, bound, surface);
    }
    if (!token.empty()) {
        wl->marshal_flags(bound.activation, 2 /* activate */, nullptr, 1, 0, token.c_str(), surface);
        wl->flush(display);
    }
    return true;
}

} // namespace rk::win

#endif
