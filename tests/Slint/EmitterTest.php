<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\GlueEmitter;
use RageKit\Cli\Slint\ScreenModel;
use RageKit\Cli\Templates\ClassInfo;

final class EmitterTest extends TestCase
{
    private function model(): ScreenModel
    {
        $class = new ClassInfo('Demo\CounterScreen', 'CounterScreen', '/C.php', true, '/C.rk',
            props: ['count' => 'int', 'name' => 'string', 'on' => 'bool', 'history' => 'list'],
            computed: ['greeting' => 'string'],
            handlers: ['press' => ['string'], 'add' => ['int'], 'reset' => []]);
        $model = new ScreenModel($class);
        $model->bind('property', 'count', 'int');                    // p1
        $name = $model->bind('property', 'name', 'string');          // p2
        $name->twoWay = true;
        $model->bind('method', 'greeting', 'string');                // p3
        $on = $model->bind('property', 'on', 'bool');                // p4
        $on->twoWay = true;
        $model->bind('property', 'history', 'list');                 // p5
        $model->action('call', 'press', [['arg', 'key']]);           // 1
        $model->action('call', 'add', [['literal', '5']]);           // 2
        $model->action('set', 'name', [], $name);                    // 3
        $model->action('set', 'on', [], $on);                        // 4
        $model->action('call', 'reset', []);                         // 5
        return $model;
    }

    public function testBinderReadsValuesAndDispatchesActions(): void
    {
        $php = BinderEmitter::emit([$this->model()]);

        self::assertStringContainsString("namespace RageKit\\Generated;", $php);
        self::assertStringContainsString("if (\$screen instanceof \\Demo\\CounterScreen) {\n            return 'CounterScreen';", $php);
        self::assertStringContainsString("            1 => \$s->count,\n            2 => \$s->name,\n            3 => \$s->greeting(),\n            4 => \$s->on,\n            5 => self::strings(\$s->history),\n", $php);
        self::assertStringContainsString("            case 1:\n                \$s->press(\$arg);\n                return 0;\n", $php);
        self::assertStringContainsString("            case 2:\n                \$s->add(5);\n                return 0;\n", $php);
        self::assertStringContainsString("            case 3:\n                \$s->name = \$arg;\n                return 2;\n", $php);
        self::assertStringContainsString("            case 4:\n                \$s->on = \$arg === '1';\n                return 4;\n", $php);
        self::assertStringContainsString("            default:\n                return 0;\n", $php);
    }

    public function testLiteralArgumentsAreConvertedAtBuildTime(): void
    {
        $class = new ClassInfo('Demo\\S', 'S', '/S.php', true, '/S.rk',
            handlers: ['go' => ['bool', 'bool', 'bool', 'int', 'float', 'string', '?int']]);
        $model = new ScreenModel($class);
        $model->action('call', 'go', [['literal', 'true'], ['literal', '0'], ['literal', ''], ['literal', '7x'], ['literal', '2'], ['literal', "it's"], ['literal', '3']]);
        $php = BinderEmitter::emit([$model]);

        self::assertStringContainsString("\$s->go(true, false, false, 7, 2.0, 'it\\'s', 3);", $php);
        self::assertStringNotContainsString("=== '1'", $php);
    }

    public function testBinderIsValidPhp(): void
    {
        $file = sys_get_temp_dir() . '/rk-binder-' . uniqid() . '.php';
        file_put_contents($file, BinderEmitter::emit([$this->model()]));
        exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    public function testGlueSetsPropertiesByTypeAndCreatesScreens(): void
    {
        $cc = GlueEmitter::emit([$this->model()]);

        self::assertStringContainsString('#include "app.h"', $cc);
        self::assertStringContainsString('class CounterScreenInstance final : public rk::Instance', $cc);
        self::assertStringContainsString('case 1: handle_->set_p1(static_cast<int>(value)); break;', $cc);
        self::assertStringContainsString('case 2: handle_->set_p2(slint::SharedString(value)); break;', $cc);
        self::assertStringContainsString('case 4: handle_->set_p4(value); break;', $cc);
        self::assertStringContainsString('case 5: handle_->set_p5(std::make_shared<slint::VectorModel<slint::SharedString>>(std::move(value))); break;', $cc);
        self::assertStringContainsString("if (screen == \"CounterScreen\") {\n        return std::make_unique<CounterScreenInstance>(window);", $cc);
    }
}
