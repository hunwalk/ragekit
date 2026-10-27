// Brings a Slint VectorModel to a new list of rows with as few changes as it takes, for the bridge's
// generated glue (rk::update_model) and the dev shell. Rows added at the front (older messages) are
// inserted there and rows dropped from the front are erased: a virtual list (<ScrollView
// virtual="true">) then keeps showing the same rows. Otherwise changed rows are replaced and rows
// are added or removed at the end, so unchanged rows keep their Slint items.
#pragma once

#include <slint.h>

#include <cstddef>
#include <vector>

namespace rk {

template <class T>
void sync_model(slint::VectorModel<T> &model, const std::vector<T> &values)
{
    size_t count = model.row_count();
    size_t n = values.size();
    // Whether the model's rows [from, from + length) equal values [at, at + length).
    auto equal = [&](size_t from, size_t at, size_t length) {
        for (size_t i = 0; i < length; ++i) {
            if (!(*model.row_data(from + i) == values[at + i])) {
                return false;
            }
        }
        return true;
    };
    if (count > 0 && n > count && equal(0, n - count, count)) {
        for (size_t i = 0; i < n - count; ++i) {
            model.insert(i, values[i]);
        }
        return;
    }
    if (n > 0 && n < count && equal(count - n, 0, n)) {
        for (size_t i = 0; i < count - n; ++i) {
            model.erase(0);
        }
        return;
    }
    for (size_t i = 0; i < n; ++i) {
        if (i >= count) {
            model.push_back(values[i]);
        } else if (!(*model.row_data(i) == values[i])) {
            model.set_row_data(i, values[i]);
        }
    }
    while (model.row_count() > n) {
        model.erase(model.row_count() - 1);
    }
}

} // namespace rk
