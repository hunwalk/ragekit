<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Stub;

/**
 * views_glue.cc: one rk::Instance per window component (C++20, compiled into the bridge library),
 * mapping property ids to the setters slint-compiler generated in app.h; id 0 sets rk-page.
 */
final class GlueEmitter
{
    private const SETTERS = [
        'string' => 'set_string(int id, std::string_view value)',
        'int' => 'set_int(int id, int64_t value)',
        'float' => 'set_float(int id, double value)',
        'bool' => 'set_bool(int id, bool value)',
        'list' => 'set_list(int id, std::vector<slint::SharedString> value)',
    ];

    /** @param list<WindowModel> $windows */
    public static function emit(array $windows): string
    {
        $instances = [];
        $creators = [];
        $frameless = false;
        foreach ($windows as $window) {
            $class = self::cppName($window->name) . 'Instance';
            $instances[] = self::instance($window);
            $creators[] = "if (screen == \"{$window->name}\") {\n    return std::make_unique<$class>(window);\n}";
            $frameless = $frameless || $window->frame !== null;
        }
        return self::stub('glue', [
            'instances' => implode("\n", $instances),
            'creators' => implode("\n", $creators),
            'frameless' => $frameless ? 'true' : 'false',
        ]);
    }

    /** The C++ name slint-compiler gives a component: rk-screens-AppWindow → rk_screens_AppWindow. */
    private static function cppName(string $component): string
    {
        return str_replace('-', '_', $component);
    }

    private static function instance(WindowModel $window): string
    {
        $cases = array_fill_keys(array_keys(self::SETTERS), []);
        // Property id 0 picks the page: the screen on top of the window's navigator.
        $cases['int'][] = 'case 0: handle_->set_rk_page(static_cast<int>(value)); break;';
        $bindings = array_values($window->bindings);
        foreach ($window->pages as $page) {
            $bindings = [...$bindings, ...array_values($page->bindings)];
        }
        usort($bindings, fn(Binding $a, Binding $b) => $a->id <=> $b->id);
        // Slint's `int` is 32-bit and `float` is f32, so the bridge's int64/double narrow here.
        foreach ($bindings as $binding) {
            $setter = 'handle_->set_' . $binding->slintName();
            $cases[$binding->type][] = "case {$binding->id}: " . match ($binding->type) {
                'string' => "$setter(slint::SharedString(value));",
                'int' => "$setter(static_cast<int>(value));",
                'float' => "$setter(static_cast<float>(value));",
                'bool' => "$setter(value);",
                'list' => "$setter(std::make_shared<slint::VectorModel<slint::SharedString>>(std::move(value)));",
            } . ' break;';
        }
        $setters = '';
        foreach (self::SETTERS as $type => $signature) {
            $setters .= self::stub('glue-setter', ['signature' => $signature, 'cases' => implode("\n", $cases[$type])]);
        }
        $framed = $window->frame !== null;
        return self::stub('glue-instance', [
            'name' => self::cppName($window->name),
            'windowCommands' => $framed ? self::stub('glue-window-commands', []) : '',
            'frameless' => $framed ? self::stub('glue-frameless', []) : '',
            'setters' => $setters,
        ]);
    }

    /** @param array<string, string> $values */
    private static function stub(string $name, array $values): string
    {
        return Stub::render(__DIR__ . "/stubs/$name.cc.stub", $values);
    }
}
