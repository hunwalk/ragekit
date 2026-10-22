<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Stub;

/**
 * views_glue.cc: one rk::Instance per screen (C++20, compiled into the bridge library), mapping
 * property ids to the setters slint-compiler generated in app.h.
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

    /** @param list<ScreenModel> $screens */
    public static function emit(array $screens): string
    {
        $instances = [];
        $creators = [];
        $frameless = false;
        foreach ($screens as $screen) {
            $name = $screen->class->short;
            $instances[] = self::instance($screen);
            $creators[] = "if (screen == \"$name\") {\n    return std::make_unique<{$name}Instance>(window);\n}";
            $frameless = $frameless || $screen->layout !== null;
        }
        return self::stub('glue', [
            'instances' => implode("\n", $instances),
            'creators' => implode("\n", $creators),
            'frameless' => $frameless ? 'true' : 'false',
        ]);
    }

    private static function instance(ScreenModel $screen): string
    {
        $cases = array_fill_keys(array_keys(self::SETTERS), []);
        // Slint's `int` is 32-bit and `float` is f32, so the bridge's int64/double narrow here.
        foreach ($screen->bindings as $binding) {
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
        $framed = $screen->layout !== null;
        return self::stub('glue-instance', [
            'name' => $screen->class->short,
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
