<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\GlueEmitter;
use RageKit\Cli\Slint\ScreenModel;
use RageKit\Cli\Slint\WindowModel;
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
        $php = BinderEmitter::emit([$this->model()->window]);

        self::assertStringContainsString("namespace RageKit\\Generated;", $php);
        self::assertStringContainsString("if (\$screen instanceof \\Demo\\CounterScreen) {\n            return 'rk-screens';", $php);
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
        $php = BinderEmitter::emit([$model->window]);

        self::assertStringContainsString("\$s->go(true, false, false, 7, 2.0, 'it\\'s', 3);", $php);
        self::assertStringNotContainsString("=== '1'", $php);
    }

    public function testBinderIsValidPhp(): void
    {
        $file = sys_get_temp_dir() . '/rk-binder-' . uniqid() . '.php';
        file_put_contents($file, BinderEmitter::emit([$this->model()->window]));
        exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    public function testGlueSetsPropertiesByTypeAndCreatesScreens(): void
    {
        $cc = GlueEmitter::emit([$this->model()->window]);

        self::assertStringContainsString('#include "app.h"', $cc);
        self::assertStringContainsString('class rk_screensInstance final : public rk::Instance', $cc);
        self::assertStringContainsString('case 1: handle_->set_p1(static_cast<int>(value)); break;', $cc);
        self::assertStringContainsString('case 2: handle_->set_p2(slint::SharedString(value)); break;', $cc);
        self::assertStringContainsString('case 4: handle_->set_p4(value); break;', $cc);
        self::assertStringContainsString('case 5: handle_->set_p5(std::make_shared<slint::VectorModel<slint::SharedString>>(std::move(value))); break;', $cc);
        self::assertStringContainsString("if (screen == \"rk-screens\") {\n        return std::make_unique<rk_screensInstance>(window);", $cc);
        // Property id 0 picks the page (the screen on top of the window's navigator).
        self::assertStringContainsString('case 0: handle_->set_rk_page(static_cast<int>(value)); break;', $cc);
    }

    /** Two screens of one layout: a window with two pages, window-level bindings and actions. */
    private function layoutWindow(): WindowModel
    {
        $layout = new ClassInfo('Demo\\AppLayout', 'AppLayout', '/L.php', false, '/L.rk', props: ['query' => 'string'], handlers: ['close' => []], isLayout: true);
        $window = new WindowModel('rk-screens-AppLayout', $layout);
        $a = new ScreenModel(new ClassInfo('Demo\\Albums', 'Albums', '/A.php', true, '/A.rk', props: ['count' => 'int'], handlers: ['add' => []]), $layout, $window);
        $b = new ScreenModel(new ClassInfo('Demo\\Photos', 'Photos', '/P.php', true, '/P.rk', props: ['zoom' => 'float'], handlers: ['zoomIn' => []]), $layout, $window);
        $a->bind('property', 'query', 'string', 'layout');     // p1
        $a->bind('method', 'title', 'string', 'context');      // p2
        $a->bind('method', 'toastTexts', 'list', 'window');    // p3
        $a->bind('property', 'count', 'int');                  // p4
        $b->bind('property', 'zoom', 'float');                 // p5
        $a->action('call', 'close', [], null, 'layout');       // 1
        $a->action('call', 'add', []);                         // 2
        $b->action('call', 'zoomIn', []);                      // 3
        return $window;
    }

    public function testBinderKnowsWindowsAndPages(): void
    {
        $php = BinderEmitter::emit([$this->layoutWindow()]);
        self::assertStringContainsString("if (\$screen instanceof \\Demo\\Photos) {\n            return 'rk-screens-AppLayout';", $php);
        self::assertStringContainsString("if (\$screen instanceof \\Demo\\Photos) {\n            return 1;", $php);
        self::assertStringContainsString('public static function values(Navigator $n): array', $php);
        self::assertStringContainsString('public static function dispatch(Navigator $n, int $action, string $arg): int', $php);
        // Every page reads the window-level values too: the layout's, the top screen's (context), the toasts.
        self::assertStringContainsString("            1 => \$l->query,\n            2 => \$s->title(),\n            3 => self::strings(\$n->toastTexts()),\n            4 => \$s->count,\n", $php);
        self::assertStringContainsString("            1 => \$l->query,\n            2 => \$s->title(),\n            3 => self::strings(\$n->toastTexts()),\n            5 => \$s->zoom,\n", $php);
    }

    /** Review Focus 1: a click from the page just left (another page's action id) does nothing. */
    public function testBinderDispatchIgnoresOtherPages(): void
    {
        $php = BinderEmitter::emit([$this->layoutWindow()]);
        $albums = substr($php, strpos($php, 'private static function dispatchAlbums'));
        $albums = substr($albums, 0, strpos($albums, "\n    }\n") + 7);
        $photos = substr($php, strpos($php, 'private static function dispatchPhotos'));
        $photos = substr($photos, 0, strpos($photos, "\n    }\n") + 7);
        self::assertStringContainsString("case 1:\n                \$l->close();", $albums);
        self::assertStringContainsString("case 2:\n                \$s->add();", $albums);
        self::assertStringNotContainsString('case 3:', $albums);
        self::assertStringContainsString("case 1:\n                \$l->close();", $photos);
        self::assertStringContainsString("case 3:\n                \$s->zoomIn();", $photos);
        self::assertStringNotContainsString('case 2:', $photos);
        $file = sys_get_temp_dir() . '/rk-binder-' . uniqid() . '.php';
        file_put_contents($file, $php);
        exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    public function testGlueHasOneInstancePerWindow(): void
    {
        $cc = GlueEmitter::emit([$this->layoutWindow(), $this->model()->window]);
        self::assertSame(1, substr_count($cc, 'class rk_screens_AppLayoutInstance final : public rk::Instance'));
        self::assertSame(1, substr_count($cc, 'class rk_screensInstance final : public rk::Instance'));
        self::assertStringContainsString("if (screen == \"rk-screens-AppLayout\") {\n        return std::make_unique<rk_screens_AppLayoutInstance>(window);", $cc);
        // Both pages' properties are set on the one window component.
        self::assertStringContainsString('case 4: handle_->set_p4(static_cast<int>(value)); break;', $cc);
        self::assertStringContainsString('case 5: handle_->set_p5(static_cast<float>(value)); break;', $cc);
        self::assertStringContainsString('return true;', $cc);
    }
}
