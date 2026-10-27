// Inside the bridge: one Instance per open window. The generated views_glue.cc implements one
// subclass per Screen (property ids → setters) and rk::create_instance().
#pragma once

#include <slint.h>

#include "model_sync.h"

#include <cstdint>
#include <memory>
#include <optional>
#include <string>
#include <string_view>
#include <variant>
#include <vector>

namespace rk {

/** One cell of a pushed row (rk_rows_*): what the generated glue reads into a struct field. */
using Cell = std::variant<std::monostate, slint::SharedString, int64_t, double, bool, slint::Image>;
using Row = std::vector<Cell>;
using Rows = std::vector<Row>;

/** Cell `i` of `row` as a field of that type; a missing cell or another type gives the default. */
template <class T>
T cell(const Row &row, size_t i)
{
    if (i < row.size()) {
        if (auto *value = std::get_if<T>(&row[i])) {
            return *value;
        }
    }
    return T();
}

inline slint::SharedString cell_string(const Row &row, size_t i) { return cell<slint::SharedString>(row, i); }
// Slint's `int` is 32-bit and `float` is f32: narrowed here, like single values.
inline int cell_int(const Row &row, size_t i) { return static_cast<int>(cell<int64_t>(row, i)); }
inline float cell_float(const Row &row, size_t i) { return static_cast<float>(cell<double>(row, i)); }
inline bool cell_bool(const Row &row, size_t i) { return cell<bool>(row, i); }
inline slint::Image cell_image(const Row &row, size_t i) { return cell<slint::Image>(row, i); }

/**
 * Brings `model` to `rows` (each made into a T by `make`) with as few changes as it takes (see
 * sync_model()). Returns true when the model was just created (the caller sets the property to it once).
 */
template <class T, class Make>
bool update_model(std::shared_ptr<slint::VectorModel<T>> &model, const Rows &rows, Make make)
{
    bool created = model == nullptr;
    if (created) {
        model = std::make_shared<slint::VectorModel<T>>();
    }
    std::vector<T> values;
    values.reserve(rows.size());
    for (const auto &row : rows) {
        values.push_back(make(row));
    }
    sync_model(*model, values);
    return created;
}

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
    /** A collection's rows (or a list shown as images); see update_model(). */
    virtual void set_rows(int, const Rows &) {}
    /** A registered image (App::assets()), embedded in the RkAssets global; nullopt if none. */
    virtual std::optional<slint::Image> asset(int) { return std::nullopt; }
    /** Screens with a layout: frameless windows with an app-drawn title bar. */
    virtual bool frameless() const { return false; }
    virtual void set_maximized(bool) {}
    /** The clipboard, through the window's hidden TextInput (RageKit\Clipboard). */
    virtual void copy_text(std::string_view) {}
    virtual std::string paste_text() { return ""; }
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
