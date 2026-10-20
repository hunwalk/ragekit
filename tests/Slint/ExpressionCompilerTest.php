<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Rageblade\Parser;
use RageKit\Cli\Slint\ExpressionCompiler;
use RageKit\Cli\Slint\Ref;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\ScreenModel;
use RageKit\Cli\Slint\ValueCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

final class ExpressionCompilerTest extends TestCase
{
    private ScreenModel $model;
    private Scope $scope;
    private ExpressionCompiler $compiler;
    private Element $at;

    protected function setUp(): void
    {
        $class = new ClassInfo('Demo\S', 'S', '/S.php', true, '/S.rk',
            props: ['count' => 'int', 'name' => 'string', 'ok' => 'bool', 'items' => 'list', 'ratio' => 'float'],
            computed: ['label' => 'string', 'total' => 'int', 'go' => 'void'],
            handlers: ['label' => [], 'total' => [], 'go' => []],
            constants: ['LIMIT' => 8, 'TITLE' => 'Hi']);
        $this->model = new ScreenModel($class);
        $this->scope = (new Scope($class, $this->model))->with('item', new Ref('string', 'item'))->with('i', new Ref('int', 'i'));
        $this->compiler = new ExpressionCompiler(new ValueCompiler());
        $this->at = new Element('Label', null, [], [], 7, 'S.rk');
    }

    private function c(string $php): Ref
    {
        return $this->compiler->compile(Parser::parse($php, 'S.rk', 7), $this->scope, $this->at);
    }

    private function p(string $name): string
    {
        return 'root.' . $this->model->bindings["screen:property:$name"]->slintName();
    }

    public function testArithmetic(): void
    {
        $r = $this->c('$count + 1');
        self::assertSame('int', $r->type);
        self::assertSame('(' . $this->p('count') . ' + 1)', $r->slint);
        self::assertSame('float', $this->c('$count * $ratio')->type);
        $div = $this->c('$count / 2');
        self::assertSame('float', $div->type);
        self::assertStringContainsString('1.0', $div->slint);
        self::assertSame('Math.mod(' . $this->p('count') . ', 3)', $this->c('$count % 3')->slint);
    }

    public function testLogicAndComparison(): void
    {
        $r = $this->c('$count > 0 && $ok');
        self::assertSame('bool', $r->type);
        self::assertSame('((' . $this->p('count') . ' > 0) && ' . $this->p('ok') . ')', $r->slint);
        self::assertSame('(item == "x")', $this->c("\$item === 'x'")->slint);
        self::assertSame('(item != "x")', $this->c("\$item != 'x'")->slint);
        self::assertSame('!(' . $this->p('ok') . ')', $this->c('!$ok')->slint);
    }

    public function testConcatenation(): void
    {
        $r = $this->c("\$name . ' (' . \$count . ')'");
        self::assertSame('string', $r->type);
        self::assertSame('"\{' . $this->p('name') . '} (\{' . $this->p('count') . '})"', $r->slint);
        self::assertSame('"\{(i + 1)}. \{item}"', $this->c("(\$i + 1) . '. ' . \$item")->slint);
    }

    public function testMethodsCountConstantsTernary(): void
    {
        $label = $this->c('label()');
        self::assertSame('string', $label->type);
        self::assertSame('root.' . $this->model->bindings['screen:method:label']->slintName(), $label->slint);
        self::assertSame($label->slint, $this->c('$this->label()')->slint);
        $count = $this->c('count($items) > 0');
        self::assertSame('(' . $this->p('items') . '.length > 0)', $count->slint);
        self::assertSame('8', $this->c('self::LIMIT')->slint);
        self::assertSame('int', $this->c('$ok ? 1 : 2')->type);
        self::assertSame('float', $this->c('$ok ? 1 : 2.5')->type);
    }

    public function testText(): void
    {
        $hello = $this->compiler->compileText('Hello, {{ $name }}!', $this->scope, $this->at);
        self::assertSame('"Hello, \{' . $this->p('name') . '}!"', $hello);
        $total = $this->compiler->compileText('Total: {{ $count + 1 }} / {count}', $this->scope, $this->at);
        self::assertSame('"Total: \{(' . $this->p('count') . ' + 1)} / \{' . $this->p('count') . '}"', $total);
        self::assertSame('"a \"b\""', $this->compiler->compileText('a "b"', $this->scope, $this->at));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function errors(): iterable
    {
        yield 'non-bool condition' => ['$count ? 1 : 2', 'S.rk:7: the condition $count is an int; conditions need a bool (e.g. $count > 0)'];
        yield 'mixed compare' => ['$name == $count', 'S.rk:7: == compares a string with an int'];
        yield 'bool in text' => ["\$ok . 'x'", "S.rk:7: can't join a bool to text; use a ternary, e.g. (\$ok ? 'yes' : 'no')"];
        yield 'string plus' => ["\$name + 'x'", 'S.rk:7: + needs numbers; join text with .'];
        yield 'unknown' => ['$nope', "S.rk:7: 'nope' is not a property, method or constant of S"];
        yield 'method as var' => ['$label', "S.rk:7: label is a method: write label()"];
        yield 'method args' => ['label(1)', "S.rk:7: label(): methods shown in templates can't take arguments"];
        yield 'void method' => ['go()', "S.rk:7: 'go()' returns void"];
        yield 'mod float' => ['$ratio % 2', 'S.rk:7: % needs whole numbers'];
        yield 'array' => ["['a']", "S.rk:7: arrays are only allowed in @style([...])"];
        yield 'string compare' => ['$name < $item', 'S.rk:7: < compares numbers only'];
        yield 'list in text' => ["\$items . 'x'", "S.rk:7: \$items is a list; show its items with @foreach"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $php, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->c($php);
    }
}
