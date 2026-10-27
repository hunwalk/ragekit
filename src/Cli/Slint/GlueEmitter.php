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
        'image' => 'set_image(int id, const slint::Image &value)',
        'rows' => 'set_rows(int id, const rk::Rows &rows)',
    ];

    /**
     * @param list<WindowModel> $windows
     * @param bool $assets whether app.slint has the RkAssets global (registered images)
     * @param bool $rich   whether app.slint uses the RkRich global (<Text markdown>); slint-compiler
     *                     leaves out globals nothing uses, and their C++ with them
     * @param bool $emoji  whether app.slint uses the RkEmoji global (<Emoji>)
     */
    public static function emit(array $windows, bool $assets = false, bool $rich = false, bool $emoji = false): string
    {
        $instances = [];
        $creators = [];
        $frameless = false;
        foreach ($windows as $window) {
            $class = self::cppName($window->name) . 'Instance';
            $instances[] = self::instance($window, $assets, $rich, $emoji);
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

    private static function instance(WindowModel $window, bool $assets, bool $rich = false, bool $emoji = false): string
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
        $members = [];
        foreach ($bindings as $binding) {
            $setter = 'handle_->set_' . $binding->slintName();
            if ($binding->type === 'rows' || $binding->type === 'images') {
                // One model per property, updated in place (rk::update_model): unchanged rows stay.
                $model = 'model_' . $binding->slintName() . '_';
                $type = $binding->type === 'rows' ? $binding->row->struct : 'slint::Image';
                $members[] = "std::shared_ptr<slint::VectorModel<$type>> $model;";
                $make = $binding->type === 'rows' ? self::makeRow($binding->row) : '[](const rk::Row &r) { return rk::cell_image(r, 0); }';
                $cases['rows'][] = "case {$binding->id}: if (rk::update_model($model, rows, $make)) { $setter($model); } break;";
                continue;
            }
            $cases[$binding->type][] = "case {$binding->id}: " . match ($binding->type) {
                'string' => "$setter(slint::SharedString(value));",
                'int' => "$setter(static_cast<int>(value));",
                'float' => "$setter(static_cast<float>(value));",
                'bool' => "$setter(value);",
                'list' => "$setter(std::make_shared<slint::VectorModel<slint::SharedString>>(std::move(value)));",
                'image' => "$setter(value);",
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
            'rich' => $rich ? self::stub('glue-rich', ['name' => self::cppName($window->name)]) : '',
            'emoji' => $emoji ? self::stub('glue-emoji', []) : '',
            'frameless' => $framed ? self::stub('glue-frameless', []) : '',
            'setters' => $setters . ($assets ? self::stub('glue-asset', []) : ''),
            'members' => implode("\n", $members),
        ]);
    }

    /** The lambda making a row struct from a pushed row's cells, field by field. */
    private static function makeRow(RowType $row): string
    {
        $fields = '';
        foreach ($row->fields() as $i => $field) {
            $fields .= " v.{$field->name} = rk::cell_{$field->type}(r, $i);";
        }
        return "[](const rk::Row &r) { {$row->struct} v;$fields return v; }";
    }

    /** @param array<string, string> $values */
    private static function stub(string $name, array $values): string
    {
        return Stub::render(__DIR__ . "/stubs/$name.cc.stub", $values);
    }
}
