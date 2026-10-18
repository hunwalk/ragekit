#include "modules.h"

#include <dlfcn.h>

#include <mutex>
#include <utility>

namespace rk::dev {

namespace {

// rk_post_fn can't carry state: the one Modules instance's handler.
std::mutex handlerLock;
Modules::EventFn handler;

void post(const char *module, int64_t handle, const char *kind, const char *data, size_t data_len)
{
    Modules::EventFn fn;
    {
        std::lock_guard<std::mutex> guard(handlerLock);
        fn = handler;
    }
    if (fn) {
        fn(module, handle, kind, std::string(data, data_len));
    }
}

using PostFn = void (*)(const char *, int64_t, const char *, const char *, size_t);

} // namespace

Modules::Modules(EventFn onEvent)
{
    std::lock_guard<std::mutex> guard(handlerLock);
    handler = std::move(onEvent);
}

bool Modules::load(const std::string &name, const std::string &path)
{
    Module module;
    module.library = dlopen(path.c_str(), RTLD_NOW | RTLD_LOCAL);
    if (module.library == nullptr) {
        const char *why = dlerror();
        module.error = why != nullptr ? why : "not found: " + path;
        modules_[name] = module;
        return false;
    }
    module.call = reinterpret_cast<decltype(module.call)>(dlsym(module.library, ("rkm_" + name + "_call").c_str()));
    module.reset = reinterpret_cast<void (*)()>(dlsym(module.library, ("rkm_" + name + "_reset").c_str()));
    auto init = reinterpret_cast<void (*)(PostFn)>(dlsym(module.library, ("rkm_" + name + "_init").c_str()));
    if (module.call == nullptr || init == nullptr) {
        module.error = path + " is not a RageKit module (no rkm_" + name + "_call/_init)";
        dlclose(module.library);
        module.library = nullptr;
        modules_[name] = module;
        return false;
    }
    init(post);
    modules_[name] = module;
    return true;
}

void Modules::unload(const std::string &name)
{
    auto it = modules_.find(name);
    if (it == modules_.end()) {
        return;
    }
    if (it->second.library != nullptr) {
        if (it->second.reset != nullptr) {
            it->second.reset();
        }
        dlclose(it->second.library);
    }
    modules_.erase(it);
}

void Modules::resetAll()
{
    for (auto &[name, module] : modules_) {
        if (module.reset != nullptr) {
            module.reset();
        }
    }
}

bool Modules::available(const std::string &name) const
{
    auto it = modules_.find(name);
    return it != modules_.end() && it->second.library != nullptr;
}

std::string Modules::error(const std::string &name) const
{
    auto it = modules_.find(name);
    return it == modules_.end() ? "not enabled in project.yml (ragekit: modules:)" : it->second.error;
}

std::vector<std::string> Modules::names() const
{
    std::vector<std::string> names;
    for (const auto &[name, module] : modules_) {
        names.push_back(name);
    }
    return names;
}

bool Modules::call(const std::string &function, const std::vector<std::string> &args, std::string &result)
{
    // The module whose name is the longest prefix "<name>_" of the function.
    const std::string *best = nullptr;
    for (const auto &[name, module] : modules_) {
        if (function.rfind(name + "_", 0) == 0 && (best == nullptr || name.size() > best->size())) {
            best = &name;
        }
    }
    if (best == nullptr) {
        result = "unknown function " + function;
        return false;
    }
    Module &module = modules_[*best];
    if (module.library == nullptr) {
        result = *best + " module not available: " + module.error;
        return false;
    }
    std::vector<const char *> pointers;
    std::vector<size_t> lengths;
    for (const auto &arg : args) {
        pointers.push_back(arg.data());
        lengths.push_back(arg.size());
    }
    int ok = 0;
    result = module.call(function.c_str(), pointers.data(), lengths.data(), args.size(), &ok);
    return ok != 0;
}

} // namespace rk::dev
