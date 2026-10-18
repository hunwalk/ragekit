// Drives a kit slider headlessly (Slint's testing backend) and prints what PHP would see:
// the value after a press at 3/4 of the track, and how many value_changed callbacks fired.
#include "slider_probe.h"
#include <slint-platform.h>
#include <slint-testing.h>
#include <cstdio>

int main()
{
    slint::testing::init();
    auto probe = Probe::create();
    probe->show();

    probe->set_value(60);  // PHP pushing a value in: must not echo back as value_changed
    slint::platform::update_timers_and_animations();
    probe->window().dispatch_pointer_move_event(slint::LogicalPosition({199, 39}));  // runs pending change handlers
    std::printf("after-set changes=%d\n", probe->get_changes());

    probe->window().dispatch_pointer_press_event(slint::LogicalPosition({150, 20}), slint::PointerEventButton::Left);
    probe->window().dispatch_pointer_release_event(slint::LogicalPosition({150, 20}), slint::PointerEventButton::Left);
    std::printf("after-press value=%.1f changes=%d\n", probe->get_value(), probe->get_changes());

    bool disabled = false;
    for (auto &e : slint::testing::ElementHandle::find_by_element_type_name(probe, "Slider")) {
        if (e.accessible_enabled() == std::optional<bool>(false)) disabled = true;
    }
    std::printf("disabled=%d\n", disabled ? 1 : 0);
    return 0;
}
