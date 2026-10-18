// Inside the bridge: one Instance per open window. The generated views_glue.cc implements one
// subclass per Screen (property ids → setters) and rk::create_instance().
#pragma once

#include <slint.h>

#include <cstdint>
#include <memory>
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
};

/** Generated: creates the compiled screen named `screen`, or nullptr. */
std::unique_ptr<Instance> create_instance(std::string_view screen, int64_t window);

/** Called by generated code: a screen's action(id, arg) callback / a close request. */
void emit_action(int64_t window, int action, std::string_view arg);
void emit_close(int64_t window);

} // namespace rk
