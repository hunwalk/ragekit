// ragekit-devshell [--headless] [--on-top] [--restore] [--renderer r] [--log file]
//                  --screen Name=entry.slint … -- php bootstrap.php
// --restore: the first PHP restores the last state snapshot too (the shell itself restarted).
// --status 127.0.0.1:port: connect there and write "reloaded" / "reload-failed" after each reload
// (the first line is RAGEKIT_DEV_STATUS_TOKEN).
// --log -: log to stderr.
// --module name=path: a native module's library (repeatable); control `reload-module name path`.
// Started by `ragekit dev`, which sends reload / restart-php / stop on stdin.
#include "platform.h"
#include "shell.h"
#include "window.h"

#ifdef SLINT_FEATURE_EXPERIMENTAL
#include <slint-testing.h>
#endif

#include <csignal>
#include <cstdio>
#include <cstdlib>
#include <iostream>
#include <string>
#include <thread>
#include <vector>

int main(int argc, char **raw)
{
    std::vector<std::string> argv = rk::dev::platform::arguments(argc, raw);
    argc = static_cast<int>(argv.size());
    rk::dev::Options options;
    std::string renderer = "software";
    bool restore = false;
    int i = 1;
    for (; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--") {
            ++i;
            break;
        }
        if (arg == "--headless") {
            options.headless = true;
        } else if (arg == "--on-top") {
            options.onTop = true;
        } else if (arg == "--restore") {
            restore = true;
        } else if (arg == "--renderer" && i + 1 < argc) {
            renderer = argv[++i];
        } else if (arg == "--status" && i + 1 < argc) {
            options.status = argv[++i];
        } else if (arg == "--log" && i + 1 < argc) {
            options.log = argv[++i];
        } else if (arg == "--module" && i + 1 < argc) {
            std::string value = argv[++i];
            auto eq = value.find('=');
            if (eq != std::string::npos) {
                options.modules[value.substr(0, eq)] = value.substr(eq + 1);
            }
        } else if (arg == "--screen" && i + 1 < argc) {
            std::string value = argv[++i];
            auto eq = value.find('=');
            if (eq != std::string::npos) {
                options.screens[value.substr(0, eq)] = value.substr(eq + 1);
            }
        } else {
            std::fprintf(stderr, "ragekit-devshell: unknown argument %s\n", arg.c_str());
            return 2;
        }
    }
    for (; i < argc; ++i) {
        options.php.emplace_back(argv[i]);
    }
    if (options.php.empty() || options.screens.empty()) {
        std::fprintf(stderr, "usage: ragekit-devshell [--headless] [--on-top] [--renderer r] [--log file] --screen Name=entry.slint … -- php bootstrap.php\n");
        return 2;
    }

#ifndef _WIN32
    std::signal(SIGPIPE, SIG_IGN);
#endif
    if (const char *token = std::getenv("RAGEKIT_DEV_STATUS_TOKEN")) {
        options.statusToken = token;
        rk::dev::platform::unsetEnv("RAGEKIT_DEV_STATUS_TOKEN"); // not for PHP
    }
    if (restore) {
        rk::dev::platform::setEnv("RAGEKIT_DEV_RESTART", "1");
    } else {
        rk::dev::platform::unsetEnv("RAGEKIT_DEV_RESTART");
    }
    if (options.headless) {
#ifdef SLINT_FEATURE_EXPERIMENTAL
        slint::testing::init();
#endif
    } else {
        if (std::getenv("SLINT_BACKEND") == nullptr) {
            rk::dev::platform::setEnv("SLINT_BACKEND", "winit-" + renderer);
        }
        // Wayland has no always-on-top request; XWayland honours the X11 hint.
        if (options.onTop) {
            rk::dev::platform::unsetEnv("WAYLAND_DISPLAY");
        }
    }

    rk::dev::Shell shell(options);
    if (!shell.load()) {
        return 1;
    }
    if (!options.headless && shell.anyFrameless()) {
        rk::win::prefer_x11(); // before Slint's platform starts (initPlatform)
    }
    shell.initPlatform();
    shell.loadModules();
    if (!shell.startPhp()) {
        return 1;
    }
    std::thread([&shell] {
        std::string line;
        while (std::getline(std::cin, line)) {
            slint::invoke_from_event_loop([&shell, line] { shell.control(line); });
        }
        slint::invoke_from_event_loop([&shell] { shell.control("stop"); });
    }).detach();
    slint::run_event_loop(slint::EventLoopMode::RunUntilQuit);
    shell.resetModules(); // stop module threads before exiting
    std::fflush(stdout);
    std::_Exit(shell.exitCode()); // reader threads may still block in read(); don't join them
}
