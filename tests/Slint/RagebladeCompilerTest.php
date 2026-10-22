<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\TemplateException;

/** Rageblade directives and {{ }} in screen templates. */
final class RagebladeCompilerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-blade-' . uniqid();
        mkdir($this->dir);
    }

    /** Compiles $rk and runs the real slint-compiler on the result (skipped without the toolchain). */
    private function compileWithSlint(string $rk, string $kit = 'material', array $constants = []): CompiledViews
    {
        $out = $this->compile($rk, $kit, $constants);
        $compiler = \RageKit\Tests\Support\InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler)) {
            self::markTestSkipped('needs the Slint toolchain');
        }
        file_put_contents("$this->dir/app.slint", $out->slint);
        $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '-o', "$this->dir/app.h"];
        foreach ($out->libraries as $name => $path) {
            array_push($command, '-L', "$name=$path");
        }
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines) . "\n" . $out->slint);
        return $out;
    }

    private function compile(string $rk, string $kit = 'material', array $constants = []): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk",
            props: ['items' => 'list', 'name' => 'string', 'count' => 'int', 'ok' => 'bool', 'sel' => 'string'],
            computed: ['label' => 'string'],
            handlers: ['label' => [], 'pick' => ['string'], 'move' => ['int', 'int'], 'tag' => ['string', 'bool', 'float']],
            requiredParams: ['pick' => 1, 'move' => 2, 'tag' => 3], constants: $constants);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        return (new SlintCompiler($kits, $kit))->compile(['Demo\S' => $class]);
    }

    public function testForeachWithIndex(): void
    {
        $out = $this->compile("<VStack>\n@foreach (\$items as \$i => \$item)\n<Label text=\"{{ \$i + 1 }}. {{ \$item }}\" />\n@endforeach\n</VStack>");
        self::assertMatchesRegularExpression('/for item\[i\] in root\.p\d+ : RkLabel/', $out->slint);
        self::assertStringContainsString('text: "\{(i + 1)}. \{item}";', $out->slint);
    }

    public function testForeachWithoutIndex(): void
    {
        $out = $this->compile("<VStack>\n@foreach (\$items as \$item)\n<Label text=\"{{ \$item }}\" />\n@endforeach\n</VStack>");
        self::assertMatchesRegularExpression('/for item in root\.p\d+ : RkLabel/', $out->slint);
    }

    public function testIfElseifElse(): void
    {
        $out = $this->compile("<VStack>\n@if (\$count > 1)\n<Label text=\"many\" />\n@elseif (\$count === 1)\n<Label text=\"one\" />\n@else\n<Label text=\"none\" />\n@endif\n</VStack>");
        preg_match_all('/if (.+?) : RkLabel/', $out->slint, $m);
        self::assertCount(3, $m[1]);
        self::assertMatchesRegularExpression('/^\(root\.p\d+ > 1\)$/', $m[1][0]);
        self::assertMatchesRegularExpression('/^!\(\(root\.p\d+ > 1\)\) && \(root\.p\d+ == 1\)$/', $m[1][1]);
        self::assertMatchesRegularExpression('/^!\(\(root\.p\d+ > 1\)\) && !\(\(root\.p\d+ == 1\)\)$/', $m[1][2]);
    }

    public function testNestedDirectives(): void
    {
        $out = $this->compile("<VStack>\n@foreach (\$items as \$a)\n<VStack>\n@if (\$ok)\n<VStack>\n@foreach (\$items as \$b)\n<Label text=\"{{ \$a . \$b }}\" />\n@endforeach\n</VStack>\n@endif\n</VStack>\n@endforeach\n</VStack>");
        self::assertStringContainsString('text: "\{a}\{b}";', $out->slint);
    }

    public function testTwoWayFromBraces(): void
    {
        $out = $this->compile('<Entry value="{{ $name }}" />');
        self::assertTrue($out->screens[0]->bindings['screen:property:name']->twoWay);
    }

    public function testHandlerWithExpressionArguments(): void
    {
        $out = $this->compile("<VStack>\n@foreach (\$items as \$i => \$item)\n<HStack>\n<Button text=\"x\" onClick=\"move(\$i, 1)\" />\n<Button text=\"y\" onClick=\"pick(\$item)\" />\n<Button text=\"z\" onClick=\"tag(\$item, \$ok, \$count / 2)\" />\n</HStack>\n@endforeach\n</VStack>");
        [$move, $pick, $tag] = $out->screens[0]->actions;
        self::assertSame([['expr', 0], ['literal', '1']], $move->args);
        self::assertSame([['expr', 0]], $pick->args);
        self::assertSame([['expr', 0], ['expr', 1], ['expr', 2]], $tag->args);
        self::assertStringContainsString('root.action(' . $tag->id . ', "\{item}\u{1f}\{(', $out->slint);
        $php = BinderEmitter::emit($out->screens);
        self::assertStringContainsString('$s->move((int) self::part($arg, 0), 1);', $php);
        self::assertStringContainsString("\$s->tag(self::part(\$arg, 0), self::part(\$arg, 1) === '1', (float) self::part(\$arg, 2));", $php);
    }

    public function testOldEachWarns(): void
    {
        $out = $this->compile("<VStack>\n<Label each=\"x in items\" text=\"{x}\" />\n<Label when=\"ok\" text=\"a\" />\n</VStack>");
        self::assertCount(1, $out->warnings);
        self::assertStringContainsString('S.rk: each= and when= still work but are replaced by @foreach and @if', $out->warnings[0]);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function errors(): iterable
    {
        yield 'two in foreach' => ["<VStack>\n@foreach (\$items as \$i)\n<Label />\n<Label />\n@endforeach\n</VStack>", 'S.rk:2: @foreach must contain one element; wrap several in a <View> or <VStack>'];
        yield 'two in branch' => ["<VStack>\n@if (\$ok)\n<Label />\n<Label />\n@endif\n</VStack>", 'S.rk:2: each @if branch must contain one element; wrap several in a <View> or <VStack>'];
        yield 'non-bool if' => ["<VStack>\n@if (\$count)\n<Label />\n@endif\n</VStack>", 'the condition $count is an int'];
        yield 'foreach over string' => ["<VStack>\n@foreach (\$name as \$c)\n<Label />\n@endforeach\n</VStack>", 'S.rk:2: @foreach needs a list; $name is a string'];
        yield 'literal arg type' => ["<VStack>\n<Button text=\"x\" onClick=\"move(\$count, 'abc')\" />\n</VStack>", "move(): argument 2 is the text 'abc', the parameter is an int"];
        yield 'float literal for int' => ["<VStack>\n<Button text=\"x\" onClick=\"move(\$count, 1.5)\" />\n</VStack>", 'move(): argument 2 is 1.5, the parameter is an int'];
        yield 'arg type' => ["<VStack>\n<Button text=\"x\" onClick=\"move(\$name, 1)\" />\n</VStack>", 'move(): argument 1 is a string, the parameter is an int'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $rk, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile($rk);
    }

    /** @return iterable<string, array{0: string}> directives straight inside directives, and mixed with each=/when= */
    public static function nestings(): iterable
    {
        yield 'filter in a loop' => ["<VStack>\n@foreach (\$items as \$a)\n@if (\$a === 'x')\n<Label text=\"{{ \$a }}\" />\n@endif\n@endforeach\n</VStack>"];
        yield 'if/else in a loop' => ["<VStack>\n@foreach (\$items as \$a)\n@if (\$a === 'x')\n<Label text=\"x\" />\n@else\n<Label text=\"y\" />\n@endif\n@endforeach\n</VStack>"];
        yield 'foreach if foreach' => ["<VStack>\n@foreach (\$items as \$a)\n@if (\$ok)\n@foreach (\$items as \$b)\n<Label text=\"{{ \$a . \$b }}\" />\n@endforeach\n@endif\n@endforeach\n</VStack>"];
        yield 'foreach over when=' => ["<VStack>\n@foreach (\$items as \$a)\n<Label when=\"ok\" text=\"{{ \$a }}\" />\n@endforeach\n</VStack>"];
        yield 'if over each=' => ["<VStack>\n@if (\$ok)\n<Label each=\"a in items\" text=\"{a}\" />\n@endif\n</VStack>"];
        yield 'in an hstack' => ["<HStack>\n@foreach (\$items as \$a)\n@if (\$ok)\n<Label text=\"{{ \$a }}\" />\n@endif\n@endforeach\n</HStack>"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nestings')]
    public function testNestedDirectivesCompileInSlint(string $rk): void
    {
        $this->compileWithSlint($rk);
    }

    public function testConditionalAbsoluteChildInAView(): void
    {
        $out = $this->compileWithSlint("<View>\n<Label text=\"body\" />\n@if (\$ok)\n<Badge text=\"new\" style=\"corner\" />\n@endif\n</View>", 'shadcn',
            ['STYLES' => ['corner' => ['position' => 'absolute', 'top' => 4, 'right' => 4]]]);
        self::assertMatchesRegularExpression('/if root\.p\d+ : HorizontalLayout \{\s+x: parent\.width - self\.width - 4px;/', $out->slint);
    }

    public function testHandlerArgumentsMayUseLessThanAndAnd(): void
    {
        $out = $this->compileWithSlint("<VStack>\n<Button text=\"x\" onClick=\"pick(\$count < 3 && \$ok ? 'a' : 'b')\" />\n</VStack>");
        self::assertStringContainsString('<', $out->slint);
    }

    public function testExpressionOnATwoWayInputIsOneWay(): void
    {
        $out = $this->compileWithSlint('<VStack><Checkbox text="x" checked="{{ !$ok }}" /><Entry value="{{ $name . \'!\' }}" /></VStack>');
        self::assertMatchesRegularExpression('/checked: !\(root\.p\d+\);/', $out->slint);
        self::assertStringNotContainsString('toggled(', $out->slint);
        self::assertSame([], $out->screens[0]->actions);
    }

    public function testCommentedDirectivesAreIgnored(): void
    {
        $this->compileWithSlint("<VStack>\n<!--\n@if (\$ok)\n<Label text=\"old\" />\n-->\n<Label text=\"now\" />\n</VStack>");
    }
}
