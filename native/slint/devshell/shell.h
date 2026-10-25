// The dev shell: owns the windows (screens loaded with Slint's interpreter) and the app's PHP
// child, and translates between them (protocol.h). Everything runs on the UI thread; reader
// threads post their lines here with slint::invoke_from_event_loop.
#pragma once

#include "child.h"
#include "loopback.h"
#include "modules.h"

#include <slint-interpreter.h>

#include <cstdint>
#include <map>
#include <memory>
#include <set>
#include <string>
#include <vector>

namespace rk::dev {

struct Options {
    std::map<std::string, std::string> screens; // name => entry .slint
    std::vector<std::string> php;               // the PHP command
    bool onTop = false;
    bool headless = false;
    std::string log; // "-": stderr
    std::map<std::string, std::string> modules; // name => library path
    // Where `ragekit dev` takes reload outcomes ("reloaded" / "reload-failed" lines), after the token.
    std::string status;
    std::string statusToken;
};

class Shell {
public:
    explicit Shell(Options options);

    bool load();         // compiles every screen; on errors prints them and keeps the old ones
    void initPlatform(); // before startPhp(): reader threads need Slint's platform
    void loadModules();  // after initPlatform(): module events hop to the UI thread
    void resetModules();
    void stopTimers(); // the PHP that started them is gone
    bool startPhp();     // starts (or, after restartPhp(), restarts) the PHP child
    void control(const std::string &line); // a line from stdin: reload, restart-php, stop, click, advance
    bool anyFrameless() const;             // a screen has a layout (after load())
    int exitCode() const { return exitCode_; }

private:
    using Instance = slint::ComponentHandle<slint::interpreter::ComponentInstance>;
    struct Win {
        std::string screen;
        std::string title;
        Instance instance;
        bool orphan = false;
        bool maximized = false; // last state reported to PHP (frameless windows)
        // set-rows models by property, updated in place; a rebuilt window starts without them.
        std::map<std::string, std::shared_ptr<slint::VectorModel<slint::interpreter::Value>>> models;
    };

    void onPhpLine(unsigned generation, const std::string &line);
    void onPhpExit(unsigned generation, int code);
    void reload();
    void restartPhp();
    void stop();
    Instance create(int64_t id, const std::string &screen, const std::string &title);
    void emit(int64_t window, int action, const std::string &arg);
    void set(Win &win, const std::string &kind, const std::string &property, const std::vector<std::string> &fields);
    bool setRows(Win &win, const std::string &property, const std::vector<std::string> &fields);
    void closeWindow(int64_t id);
    void windowCommand(int64_t id, const std::string &command);
    bool frameless(const std::string &screen) const;
    void show(Instance &instance, const std::string &screen);
    void log(const std::string &line) const;
    void status(const std::string &line) const;

    Options options_;
    Modules modules_;
    Child child_;
    std::map<std::string, slint::interpreter::ComponentDefinition> defs_;
    std::map<int64_t, Win> windows_;
    std::set<std::string> warned_;
    // RageKit\Timer's timers by id; stopped ones are parked until the next event-loop turn (a tick
    // may stop its own timer).
    std::map<int64_t, std::unique_ptr<slint::Timer>> timers_;
    std::vector<std::unique_ptr<slint::Timer>> parkedTimers_;
    void stopTimer(int64_t id);
    int64_t next_ = 1;
    bool ready_ = false;   // PHP sent `run`: events are delivered
    bool running_ = false; // a PHP child is alive
    bool quitting_ = false;
    bool hadWindow_ = false; // a window was opened since the shell started
    int exitCode_ = 0;
    loopback::Socket status_ = loopback::none;
};

} // namespace rk::dev
