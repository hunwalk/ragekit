#include "shell.h"

#include "platform.h"
#include "protocol.h"
#include "window.h"

#include <slint-testing.h>

#include <array>
#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <fstream>
#include <memory>

namespace rk::dev {

namespace {

slint::Window &mutableWindow(const slint::interpreter::ComponentInstance &instance)
{
    return const_cast<slint::Window &>(instance.window()); // the interpreter only hands out const
}

} // namespace

void Shell::log(const std::string &line) const
{
    if (options_.log == "-") {
        std::fprintf(stderr, "%s\n", line.c_str());
    } else if (!options_.log.empty()) {
        std::ofstream(options_.log, std::ios::app | std::ios::binary) << line << "\n"; // "\n" on Windows too
    }
}

Shell::Shell(Options options)
    : options_(std::move(options)),
      modules_([this](std::string module, int64_t handle, std::string kind, std::string data) {
          slint::invoke_from_event_loop([this, module, handle, kind, data] {
              if (running_ && ready_) {
                  child_.send(encode({"module-event", module, std::to_string(handle), kind, data}));
              }
          });
      })
{
    if (!options_.status.empty()) {
        status_ = loopback::connect(options_.status);
        if (status_ == loopback::none) {
            std::fprintf(stderr, "ragekit dev: could not connect to %s\n", options_.status.c_str());
        } else {
            loopback::send(status_, options_.statusToken + "\n");
        }
    }
}

void Shell::loadModules()
{
    for (const auto &[name, path] : options_.modules) {
        if (!modules_.load(name, path)) {
            std::fprintf(stderr, "ragekit dev: %s module not available: %s\n", name.c_str(), modules_.error(name).c_str());
        }
    }
}

void Shell::resetModules()
{
    for (const auto &name : modules_.names()) {
        log("reset " + name);
    }
    modules_.resetAll();
}

void Shell::stopTimer(int64_t id)
{
    auto it = timers_.find(id);
    if (it == timers_.end()) {
        return;
    }
    it->second->stop();
    parkedTimers_.push_back(std::move(it->second));
    timers_.erase(it);
    slint::invoke_from_event_loop([this] { parkedTimers_.clear(); });
    log("timer " + std::to_string(id) + " stopped");
}

void Shell::stopTimers()
{
    std::vector<int64_t> ids;
    for (const auto &[id, timer] : timers_) {
        ids.push_back(id);
    }
    for (int64_t id : ids) {
        stopTimer(id);
    }
}

void Shell::status(const std::string &line) const
{
    if (status_ != loopback::none) {
        loopback::send(status_, line + "\n");
    }
}

bool Shell::load()
{
    std::map<std::string, slint::interpreter::ComponentDefinition> defs;
    bool ok = true;
    for (const auto &[name, entry] : options_.screens) {
        slint::interpreter::ComponentCompiler compiler;
        auto def = compiler.build_from_path(entry);
        for (const auto &d : compiler.diagnostics()) {
            if (d.level == slint::interpreter::DiagnosticLevel::Error) {
                std::fprintf(stderr, "ragekit dev: %s:%d: %s\n", std::string(d.source_file).c_str(), static_cast<int>(d.line), std::string(d.message).c_str());
                log("error " + std::string(d.message));
            }
        }
        if (!def) {
            ok = false;
            continue;
        }
        defs.emplace(name, *def);
    }
    if (ok) {
        defs_ = std::move(defs);
    }
    return ok;
}

void Shell::initPlatform()
{
    // Creating a component sets up Slint's platform, which invoke_from_event_loop() (used by the
    // reader threads) needs before the event loop runs.
    if (!defs_.empty()) {
        auto warmup = defs_.begin()->second.create();
    }
}

bool Shell::startPhp()
{
    ready_ = false;
    running_ = child_.start(
        options_.php,
        [this](unsigned generation, std::string line) {
            slint::invoke_from_event_loop([this, generation, line] { onPhpLine(generation, line); });
        },
        [this](unsigned generation, int code) {
            slint::invoke_from_event_loop([this, generation, code] { onPhpExit(generation, code); });
        });
    if (!running_) {
        std::fprintf(stderr, "ragekit dev: could not start %s\n", options_.php.empty() ? "php" : options_.php[0].c_str());
    }
    return running_;
}

Shell::Instance Shell::create(int64_t id, const std::string &screen, const std::string &title)
{
    auto instance = defs_.at(screen).create();
    instance->set_property("rk-title", slint::SharedString(title));
    instance->set_property("rk-on-top", options_.onTop);
    instance->set_callback("action", [this, id](auto args) {
        emit(id, static_cast<int>(args[0].to_number().value_or(0)), std::string(args[1].to_string().value_or("")));
        return slint::interpreter::Value();
    });
    if (frameless(screen)) {
        instance->set_callback("rk-window", [this, id](auto args) {
            windowCommand(id, std::string(args[0].to_string().value_or("")));
            return slint::interpreter::Value();
        });
    }
    mutableWindow(*instance).on_close_requested([this, id] {
        emit(id, 0, "");
        return slint::CloseRequestResponse::KeepWindowShown;
    });
    return instance;
}

bool Shell::frameless(const std::string &screen) const
{
    auto it = defs_.find(screen);
    if (it == defs_.end()) {
        return false;
    }
    for (const auto &name : it->second.callbacks()) {
        if (std::string_view(name) == "rk-window") {
            return true;
        }
    }
    return false;
}

bool Shell::anyFrameless() const
{
    for (const auto &[name, def] : defs_) {
        if (frameless(name)) {
            return true;
        }
    }
    return false;
}

void Shell::show(Instance &instance, const std::string &screen)
{
    instance->show();
    if (frameless(screen)) {
        rk::win::make_floating(mutableWindow(*instance));
    }
}

// A frameless window's rk-window(command): the same as the bridge's rk::window_command.
void Shell::windowCommand(int64_t id, const std::string &command)
{
    static constexpr std::string_view edges[] = {"top-left", "top", "top-right", "right", "bottom-right", "bottom", "bottom-left", "left"};
    auto it = windows_.find(id);
    if (it == windows_.end()) {
        return;
    }
    Win &win = it->second;
    auto &w = mutableWindow(*win.instance);
    if (command == "move-start") {
        if (rk::win::double_click()) {
            w.set_maximized(!w.is_maximized());
        } else {
            rk::win::start_move(w);
        }
    } else if (command == "move") {
        rk::win::move(w);
    } else if (command == "resize-move") {
        rk::win::resize(w);
    } else if (command == "minimize") {
        w.set_minimized(true);
    } else if (command == "toggle-maximize") {
        w.set_maximized(!w.is_maximized());
    } else if (command == "close") {
        emit(id, 0, "");
    } else if (command == "resized") {
        bool now = w.is_maximized();
        if (now != win.maximized) {
            win.maximized = now;
            win.instance->set_property("rk-maximized", slint::interpreter::Value(now));
            emit(id, -1, now ? "1" : "0");
        }
    } else if (command.rfind("resize-", 0) == 0) {
        for (int i = 0; i < 8; ++i) {
            if (command.substr(7) == edges[i]) {
                rk::win::start_resize(w, static_cast<rk::win::Edge>(i));
            }
        }
    }
}

void Shell::emit(int64_t window, int action, const std::string &arg)
{
    if (!running_ || !ready_) {
        log("dropped event " + std::to_string(window) + " " + std::to_string(action) + " (PHP not ready)");
        return;
    }
    child_.send(encode({"event", std::to_string(window), std::to_string(action), arg}));
}

void Shell::onPhpLine(unsigned generation, const std::string &line)
{
    if (generation != child_.generation()) {
        return; // from a PHP that was restarted meanwhile
    }
    auto f = decode(line);
    const std::string &cmd = f[0];
    if (cmd == "open" && f.size() >= 5) {
        const std::string &screen = f[1];
        for (auto &[id, win] : windows_) {
            if (win.orphan && win.screen == screen) {
                win.orphan = false;
                win.title = f[2];
                win.instance->set_property("rk-title", slint::SharedString(f[2]));
                log("adopted window " + std::to_string(id) + " (" + screen + ")");
                child_.send(encode({"opened", std::to_string(id)}));
                return;
            }
        }
        if (defs_.count(screen) == 0) {
            child_.send(encode({"opened", "0"}));
            return;
        }
        int64_t id = next_++;
        Win win{screen, f[2], create(id, screen, f[2])};
        mutableWindow(*win.instance).set_size(slint::LogicalSize({std::stof(f[3]), std::stof(f[4])}));
        show(win.instance, screen);
        windows_.emplace(id, std::move(win));
        hadWindow_ = true;
        log("opened window " + std::to_string(id) + " (" + screen + ")");
        child_.send(encode({"opened", std::to_string(id)}));
        return;
    }
    if (cmd == "call" && f.size() >= 2) {
        std::string result;
        bool ok = modules_.call(f[1], std::vector<std::string>(f.begin() + 2, f.end()), result);
        child_.send(encode({ok ? "result" : "error", result}));
        return;
    }
    if (cmd == "timer-start" && f.size() >= 4) {
        int64_t id = std::atoll(f[1].c_str());
        stopTimer(id);
        auto timer = std::make_unique<slint::Timer>();
        timer->start(f[3] == "1" ? slint::TimerMode::Repeated : slint::TimerMode::SingleShot,
                     std::chrono::milliseconds(std::atoll(f[2].c_str())), [this, id] {
                         if (running_) {
                             child_.send(encode({"module-event", "@timer", std::to_string(id), "tick", ""}));
                         }
                     });
        timers_[id] = std::move(timer);
        log("timer " + f[1] + " started");
        return;
    }
    if (cmd == "timer-stop" && f.size() >= 2) {
        stopTimer(std::atoll(f[1].c_str()));
        return;
    }
    if (cmd == "module" && f.size() >= 2) {
        child_.send(encode({"result", modules_.available(f[1]) ? "1" : "0"}));
        return;
    }
    if (cmd == "module-error" && f.size() >= 2) {
        child_.send(encode({"result", modules_.available(f[1]) ? "" : modules_.error(f[1])}));
        return;
    }
    if (cmd.rfind("set-", 0) == 0 && f.size() >= 3) {
        auto it = windows_.find(std::atoll(f[1].c_str()));
        if (it != windows_.end()) {
            set(it->second, cmd, f[2], f);
        }
        return;
    }
    if (cmd == "clipboard-set" && f.size() >= 2) {
        // RageKit\Clipboard: the window's hidden TextInput (the template's rk-copy / rk-paste).
        if (!windows_.empty()) {
            std::array<slint::interpreter::Value, 1> args{slint::interpreter::Value(slint::SharedString(f[1]))};
            windows_.begin()->second.instance->invoke("rk-copy", args);
        }
        return;
    }
    if (cmd == "clipboard-get") {
        std::string text;
        if (!windows_.empty()) {
            auto value = windows_.begin()->second.instance->invoke("rk-paste", {});
            if (auto s = value ? value->to_string() : std::nullopt) {
                text = std::string(s->data(), s->size());
            }
        }
        child_.send(encode({"result", text}));
        return;
    }
    if ((cmd == "hide" || cmd == "show") && f.size() >= 2) {
        // Window::hide() / show(): a tray app's main window.
        auto it = windows_.find(std::atoll(f[1].c_str()));
        if (it != windows_.end()) {
            if (cmd == "hide") {
                it->second.instance->hide();
            } else {
                it->second.instance->show();
            }
            log(cmd + " " + f[1]);
        }
        return;
    }
    if ((cmd == "minimize" || cmd == "maximize") && f.size() >= 2) {
        // A layout's minimize() / toggleMaximize().
        auto it = windows_.find(std::atoll(f[1].c_str()));
        if (it != windows_.end()) {
            auto &w = mutableWindow(*it->second.instance);
            if (cmd == "minimize") {
                w.set_minimized(true);
            } else {
                w.set_maximized(f.size() >= 3 && f[2] == "1");
            }
            log(cmd + " " + f[1] + (f.size() >= 3 ? " " + f[2] : ""));
        }
        return;
    }
    if (cmd == "close" && f.size() >= 2) {
        closeWindow(std::atoll(f[1].c_str()));
        return;
    }
    if (cmd == "run") {
        std::vector<int64_t> orphans;
        for (auto &[id, win] : windows_) {
            if (win.orphan) {
                orphans.push_back(id);
            }
        }
        for (int64_t id : orphans) {
            closeWindow(id);
        }
        ready_ = true;
        log("php ready");
        return;
    }
    if (cmd == "quit") {
        quitting_ = true;
        std::vector<int64_t> ids;
        for (auto &[id, win] : windows_) {
            ids.push_back(id);
        }
        for (int64_t id : ids) {
            closeWindow(id);
        }
        return;
    }
    log("error unknown message " + cmd);
}

void Shell::set(Win &win, const std::string &kind, const std::string &property, const std::vector<std::string> &f)
{
    using slint::interpreter::Value;
    bool ok = false;
    if (kind == "set-rows") {
        ok = setRows(win, property, f);
    } else if (kind == "set-list") {
        auto model = std::make_shared<slint::VectorModel<Value>>();
        for (size_t i = 3; i < f.size(); ++i) {
            model->push_back(Value(slint::SharedString(f[i])));
        }
        ok = win.instance->set_property(property, Value(std::shared_ptr<slint::Model<Value>>(model)));
    } else if (f.size() >= 4) {
        if (kind == "set-string") {
            ok = win.instance->set_property(property, Value(slint::SharedString(f[3])));
        } else if (kind == "set-int" || kind == "set-float") {
            ok = win.instance->set_property(property, Value(std::strtod(f[3].c_str(), nullptr)));
        } else if (kind == "set-bool") {
            ok = win.instance->set_property(property, Value(f[3] == "1"));
        } else if (kind == "set-image") {
            // '' clears the image; a file the interpreter can't decode is reported once.
            auto image = f[3].empty() ? slint::Image() : slint::Image::load_from_path(slint::SharedString(f[3]));
            if (!f[3].empty() && image.size().width == 0) {
                std::fprintf(stderr, "ragekit: image %s: couldn't be loaded\n", f[3].c_str());
            }
            ok = win.instance->set_property(property, Value(image));
        }
    }
    if (!ok && warned_.insert(win.screen + "." + property).second) {
        log("error can't set " + win.screen + "." + property);
        std::fprintf(stderr, "ragekit dev: %s has no property %s (template and PHP out of step?)\n", win.screen.c_str(), property.c_str());
    }
}

void Shell::closeWindow(int64_t id)
{
    auto it = windows_.find(id);
    if (it == windows_.end()) {
        return;
    }
    it->second.instance->hide();
    // Destroyed on the next loop turn: a close can arrive while this window's callback runs.
    auto parked = std::make_shared<Instance>(std::move(it->second.instance));
    windows_.erase(it);
    slint::invoke_from_event_loop([parked] { });
}

void Shell::onPhpExit(unsigned generation, int code)
{
    if (generation != child_.generation()) {
        return;
    }
    running_ = false;
    ready_ = false;
    stopTimers();
    // The app ends with its last window. PHP failing before any window opened (an error in main())
    // doesn't end the session: the next save starts PHP again.
    if (quitting_ || (windows_.empty() && (hadWindow_ || code == 0))) {
        exitCode_ = code;
        slint::quit_event_loop();
        return;
    }
    std::fprintf(stderr, "ragekit dev: PHP stopped (exit %d) — fix the error and save to restart\n", code);
    log("php stopped " + std::to_string(code));
}

// set-rows <window> <property> struct|images <rows> <columns> <type>… <cell>…: a collection's rows (a
// struct per row, fields f0…; possibly none) or a list shown as images, brought into the property's
// model in place.
bool Shell::setRows(Win &win, const std::string &property, const std::vector<std::string> &f)
{
    using slint::interpreter::Value;
    if (f.size() < 6) {
        return false;
    }
    bool images = f[3] == "images";
    size_t count = std::strtoul(f[4].c_str(), nullptr, 10);
    size_t columns = std::strtoul(f[5].c_str(), nullptr, 10);
    size_t first = 6 + columns;
    if (f.size() < first + count * columns || (images && count > 0 && columns != 1)) {
        return false;
    }
    auto cell = [&](size_t row, size_t column) -> Value {
        const std::string &type = f[6 + column];
        const std::string &text = f[first + row * columns + column];
        if (type == "s") {
            return Value(slint::SharedString(text));
        }
        if (type == "i" || type == "f") {
            return Value(std::strtod(text.c_str(), nullptr));
        }
        if (type == "b") {
            return Value(text == "1");
        }
        auto image = text.empty() ? slint::Image() : slint::Image::load_from_path(slint::SharedString(text));
        if (!text.empty() && image.size().width == 0) {
            std::fprintf(stderr, "ragekit: image %s: couldn't be loaded\n", text.c_str());
        }
        return Value(image);
    };
    auto &model = win.models[property];
    bool created = model == nullptr;
    if (created) {
        model = std::make_shared<slint::VectorModel<Value>>();
    }
    for (size_t row = 0; row < count; ++row) {
        Value value;
        if (images) {
            value = cell(row, 0);
        } else {
            slint::interpreter::Struct fields;
            for (size_t column = 0; column < columns; ++column) {
                fields.set_field("f" + std::to_string(column), cell(row, column));
            }
            value = Value(fields);
        }
        if (row >= model->row_count()) {
            model->push_back(value);
        } else if (!(*model->row_data(row) == value)) {
            model->set_row_data(row, value);
        }
    }
    while (model->row_count() > count) {
        model->erase(model->row_count() - 1);
    }
    bool ok = !created || win.instance->set_property(property, Value(std::shared_ptr<slint::Model<Value>>(model)));
    log("rows " + f[1] + " " + property + " " + std::to_string(count));
    return ok;
}

void Shell::reload()
{
    if (!load()) {
        log("reload failed");
        status("reload-failed");
        return;
    }
    for (auto &[id, win] : windows_) {
        auto fresh = create(id, win.screen, win.title);
        auto &oldWindow = mutableWindow(*win.instance);
        auto &newWindow = mutableWindow(*fresh);
        newWindow.set_position(oldWindow.position());
        newWindow.set_size(oldWindow.size());
        fresh->set_property("rk-maximized", slint::interpreter::Value(win.maximized));
        show(fresh, win.screen);
        win.instance->hide();
        win.instance = fresh;
        win.models.clear();
    }
    log("reloaded");
    status("reloaded");
    if (running_) {
        child_.send(encode({"reload"}));
    }
}

void Shell::restartPhp()
{
    if (running_) {
        child_.kill();
    }
    running_ = false;
    resetModules(); // the new PHP starts without the old one's sounds etc.
    stopTimers();   // … and starts its own timers again
    for (auto &[id, win] : windows_) {
        win.orphan = true;
    }
    platform::setEnv("RAGEKIT_DEV_RESTART", "1");
    startPhp();
}

void Shell::stop()
{
    if (running_) {
        child_.kill();
        running_ = false;
    }
    resetModules();
    stopTimers();
    for (auto &[id, win] : windows_) {
        win.instance->hide();
    }
    exitCode_ = 0;
    slint::quit_event_loop();
}

void Shell::control(const std::string &line)
{
    // Control lines are plain words separated by spaces; a click's last field is the rest of the line.
    std::vector<std::string> f;
    size_t pos = 0;
    while (pos < line.size() && f.size() < 3) {
        size_t space = line.find(' ', pos);
        f.push_back(line.substr(pos, space == std::string::npos ? std::string::npos : space - pos));
        pos = space == std::string::npos ? line.size() : space + 1;
    }
    if (pos < line.size()) {
        f.push_back(line.substr(pos));
    }
    if (f.empty()) {
        return;
    }
    if (f[0] == "reload") {
        reload();
    } else if (f[0] == "restart-php") {
        restartPhp();
    } else if (f[0] == "stop") {
        stop();
    } else if (f[0] == "reload-module" && f.size() >= 3) {
        std::string path = f.size() > 3 ? f[2] + " " + f[3] : f[2]; // the path may contain spaces
        modules_.unload(f[1]);
        bool ok = modules_.load(f[1], path);
        log(std::string(ok ? "reloaded module " : "module failed ") + f[1]);
        if (!ok) {
            std::fprintf(stderr, "ragekit dev: %s module not available: %s\n", f[1].c_str(), modules_.error(f[1]).c_str());
        }
    } else if (f[0] == "advance" && options_.headless && f.size() >= 2) {
        // Headless only: Slint's testing backend mocks time, so timers fire when a test moves it on.
        slint::cbindgen_private::slint_mock_elapsed_time(std::strtoull(f[1].c_str(), nullptr, 10));
    } else if (f[0] == "click" && options_.headless && f.size() >= 3) {
        emit(std::atoll(f[1].c_str()), std::atoi(f[2].c_str()), f.size() > 3 ? f[3] : "");
    }
}

} // namespace rk::dev
