// Inside the bridge: one Instance per open window. The generated views_glue.cc implements one
// subclass per Screen (property ids → setters) and rk::create_instance().
#pragma once

#include <slint.h>

#include <cstdint>
#include <memory>
#include <optional>
#include <string_view>
#include <vector>

namespace rk {

class Instance {
public:
    virtual ~Instance() = default;
    virtual slint::Window &window() = 0;
    virtual void show() = 0;
    virtual void hide() = 0;
    virtual void set_title(std::string_view value) = 0;
    virtual void set_string(int id, std::string_view value) = 0;
    virtual void set_int(int id, int64_t value) = 0;
    virtual void set_float(int id, double value) = 0;
    virtual void set_bool(int id, bool value) = 0;
    virtual void set_list(int id, std::vector<slint::SharedString> value) = 0;
    virtual void set_image(int id, const slint::Image &value) = 0;
    /** A registered image (App::assets()), embedded in the RkAssets global; nullopt if none. */
    virtual std::optional<slint::Image> asset(int) { return std::nullopt; }
    /** Screens with a layout: frameless windows with an app-drawn title bar. */
    virtual bool frameless() const { return false; }
    virtual void set_maximized(bool) {}
};

/** Generated: creates the compiled screen named `screen`, or nullptr. */
std::unique_ptr<Instance> create_instance(std::string_view screen, int64_t window);

/** Generated: whether any compiled screen has a layout (frameless window). */
bool any_frameless();

/** Called by generated code: a frameless window's rk-window(command) callback (see native/slint/window). */
void window_command(int64_t window, slint::Window &w, std::string_view command);

/** Called by generated code: a screen's action(id, arg) callback / a close request. */
void emit_action(int64_t window, int action, std::string_view arg);
void emit_close(int64_t window);

} // namespace rk
