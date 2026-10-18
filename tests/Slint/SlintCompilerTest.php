<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\Expression;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\KitRegistry;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

final class SlintCompilerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-compile-' . uniqid();
        mkdir($this->dir);
    }

    private function screen(string $short, string $rk, array $props = [], array $computed = [], array $handlers = [], array $constants = []): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk", $props, $computed, $handlers, $constants);
    }

    private function child(string $short, string $rk, array $props = []): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", false, "$this->dir/$short.rk", $props);
    }

    /** @param list<ClassInfo> $classes */
    /** @param list<ClassInfo> $classes  compiled with the pics fixture kit and $projectRoot */
    private function compileIn(string $projectRoot, array $classes): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/tests/fixtures/kits/pics/kit.yml'], '/rk');
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        return (new SlintCompiler($kits, 'material', $projectRoot))->compile($byName);
    }

    private function compile(array $classes, string $kit = 'material'): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/tests/fixtures/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        return (new SlintCompiler($kits, $kit))->compile($byName);
    }

    public function testCounterScreen(): void
    {
        $out = $this->compile([
            $this->screen('CounterScreen', <<<XML
                <VStack>
                    <Label text="Count: {count}" />
                    <Button text="+" onClick="increment" grow="true" />
                    <Entry value="{name}" />
                    <Checkbox text="Show" checked="{show}" />
                    <Label text="{greeting}" size="title" />
                </VStack>
                XML,
                props: ['count' => 'int', 'name' => 'string', 'show' => 'bool'],
                computed: ['greeting' => 'string', 'increment' => 'void'],
                handlers: ['increment' => [], 'greeting' => []]),
        ]);
        $slint = $out->slint;

        self::assertStringContainsString('import { RkWindow, RkLabel, RkButton, RkEntry, RkCheckbox } from "', $slint);
        self::assertStringContainsString('export component CounterScreen inherits RkWindow {', $slint);
        self::assertStringContainsString("    in property <int> p1;\n", $slint);
        self::assertStringContainsString("    in-out property <string> p2;\n", $slint);
        self::assertStringContainsString("    in-out property <bool> p3;\n", $slint);
        self::assertStringContainsString("    in property <string> p4;\n", $slint);
        self::assertStringContainsString('text: "Count: \{root.p1}";', $slint);
        self::assertStringContainsString('clicked => { root.action(1, ""); }', $slint);
        self::assertStringContainsString('text <=> root.p2;', $slint);
        self::assertStringContainsString('edited(value) => { root.action(2, value); }', $slint);
        self::assertStringContainsString('checked <=> root.p3;', $slint);
        self::assertStringContainsString('toggled(value) => { root.action(3, value ? "1" : "0"); }', $slint);
        self::assertStringContainsString('size: "title";', $slint);
        self::assertStringContainsString("VerticalLayout {\n            spacing: 8px;\n            alignment: stretch;", $slint);
        self::assertStringContainsString('vertical-stretch: 1;', $slint);
        self::assertSame(['material' => realpath(self::ROOT) . '/kits/material/library/material.slint'], $out->libraries);
        self::assertSame(['count', 'name', 'show', 'greeting'], array_map(fn($b) => $b->name, array_values($out->screens[0]->bindings)));
    }

    public function testKeypadFromNestedConstantAndItemArgument(): void
    {
        $out = $this->compile([
            $this->screen('CalcScreen', <<<XML
                <VStack>
                    <HStack each="row in KEYPAD">
                        <Button each="key in row" text="{key}" onClick="press(key)" grow="true" />
                    </HStack>
                </VStack>
                XML,
                handlers: ['press' => ['string']],
                constants: ['KEYPAD' => [['7', '÷'], ['0']]]),
        ]);

        self::assertStringContainsString('for row in [["7", "÷"], ["0"]] : HorizontalLayout {', $out->slint);
        self::assertStringContainsString('for key in row : RkButton {', $out->slint);
        self::assertStringContainsString('text: "\{key}";', $out->slint);
        self::assertStringContainsString('clicked => { root.action(1, key); }', $out->slint);
        self::assertSame([['arg', 'key']], $out->screens[0]->actions[0]->args);
    }

    public function testNegatedWhen(): void
    {
        $out = $this->compile([$this->screen('S', '<VStack><Label when="!flag" text="x" /></VStack>', props: ['flag' => 'bool'])]);
        self::assertStringContainsString('if !root.p1 :', $out->slint);
    }

    public function testOptionalHandlerParametersAreAccepted(): void
    {
        file_put_contents("$this->dir/S.rk", '<VStack><Button onClick="go" /><Button onClick="go(\'a\')" /></VStack>');
        $class = new ClassInfo('Demo\\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", handlers: ['go' => ['string', 'int']], requiredParams: ['go' => 0]);
        $out = $this->compile([$class]);
        self::assertCount(2, $out->screens[0]->actions);
    }

    public function testRequiredHandlerParametersAreEnforced(): void
    {
        $class = new ClassInfo('Demo\\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", handlers: ['go' => ['string', 'int']], requiredParams: ['go' => 1]);
        file_put_contents("$this->dir/S.rk", '<Button onClick="go" />');
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('S.rk:1: go() takes 1 to 2 argument(s), the template passes 0');
        $this->compile([$class]);
    }

    public function testHandlerWithClassParameterIsRejected(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('S.rk:1: go(): parameter 1 is a Demo\\Thing; handler parameters must be string, int, float or bool');
        $this->compile([$this->screen('S', '<Button onClick="go" />', handlers: ['go' => ['Demo\\Thing']])]);
    }

    public function testChildComponentsComeFirstInDependencyOrder(): void
    {
        $out = $this->compile([
            $this->screen('MainScreen', '<VStack><Outer when="show" items="{history}" /></VStack>',
                props: ['show' => 'bool', 'history' => 'list']),
            $this->child('Outer', '<VStack><Inner items="{items}" /></VStack>', ['items' => 'list']),
            $this->child('Inner', '<VStack padded="false"><Label each="entry in items" text="{entry}" /></VStack>', ['items' => 'list']),
        ]);
        $slint = $out->slint;

        self::assertLessThan(strpos($slint, 'component Outer {'), strpos($slint, 'component Inner {'));
        self::assertLessThan(strpos($slint, 'export component MainScreen'), strpos($slint, 'component Outer {'));
        self::assertStringContainsString("in property <[string]> rk-items;", $slint);
        self::assertStringContainsString('for entry in root.rk-items : RkLabel {', $slint);
        self::assertStringContainsString('if root.p1 : Outer {', $slint);
        self::assertStringContainsString('rk-items: root.p2;', $slint);
    }

    public function testNamespacedKitElementAndKitOverride(): void
    {
        $out = $this->compile([
            $this->screen('S', '<VStack xmlns:ui="shadcn"><ui:Card title="Hi"><Button text="x" variant="outline" /></ui:Card></VStack>'),
        ], kit: 'shadcn');

        self::assertStringContainsString('ShadCard {', $out->slint);
        self::assertStringContainsString('ShadButton {', $out->slint);
        self::assertStringContainsString('variant: "outline";', $out->slint);
        self::assertStringContainsString('inherits RkWindow', $out->slint, 'shadcn has no window: falls back to Material');
    }

    public function testSliderBindsTwoWayFloat(): void
    {
        $out = $this->compile([$this->screen('S', '<VStack><Slider value="{volume}" min="0" max="100" /></VStack>', props: ['volume' => 'float'])]);
        self::assertStringContainsString('RkSlider {', $out->slint);
        self::assertStringContainsString('value <=> root.p1;', $out->slint);
        self::assertStringContainsString('value_changed(value) => { root.action(1, "\{value}"); }', $out->slint);
        self::assertStringContainsString('minimum: 0;', $out->slint);
        self::assertStringContainsString('maximum: 100;', $out->slint);
    }

    public function testSelectTakesConstantOptions(): void
    {
        $out = $this->compile([$this->screen('S', '<VStack><Select value="{fruit}" options="{FRUITS}" /></VStack>',
            props: ['fruit' => 'string'], constants: ['FRUITS' => ['Apple', 'Dragon fruit']])]);
        self::assertStringContainsString('options: ["Apple", "Dragon fruit"];', $out->slint);
        self::assertStringContainsString('value <=> root.p1;', $out->slint);
    }

    public function testCardSeparatorProgressImage(): void
    {
        $out = $this->compileIn(__DIR__ . '/../fixtures', [$this->screen('S',
            '<VStack><Card title="Box"><Label text="in" /><Separator /></Card><Progress value="{p}" /><Image source="assets/my icon é.svg" /></VStack>',
            computed: ['p' => 'float'])]);
        self::assertStringContainsString('RkCard {', $out->slint);
        self::assertStringContainsString('title: "Box";', $out->slint);
        self::assertStringContainsString('RkSeparator {', $out->slint);
        self::assertStringContainsString('RkProgress {', $out->slint);
        self::assertStringContainsString('progress: root.p1;', $out->slint);
        self::assertStringContainsString('RkImage {', $out->slint);
        self::assertStringContainsString('source: @image-url(', $out->slint);
    }

    public function testButtonVariantIsValidated(): void
    {
        $out = $this->compile([$this->screen('S', '<Button text="x" variant="ghost" />')]);
        self::assertStringContainsString('variant: "ghost";', $out->slint);
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('variant="loud" must be one of: default, secondary, outline, ghost, destructive');
        $this->compile([$this->screen('T', '<Button text="x" variant="loud" />')]);
    }

    public function testTabsEmitTabBarAndPages(): void
    {
        $out = $this->compile([$this->screen('S', <<<XML
            <Tabs selected="{tab}">
                <Tab title="One"><Label text="first" /></Tab>
                <Tab title="Two…"><Label text="second" /></Tab>
            </Tabs>
            XML, props: ['tab' => 'int'])]);
        self::assertStringContainsString('RkTabBar {', $out->slint);
        self::assertStringContainsString('titles: ["One", "Two…"];', $out->slint);
        self::assertStringContainsString('selected <=> root.p1;', $out->slint);
        self::assertStringContainsString('changed(value) => { root.action(1, "\{value}"); }', $out->slint);
        self::assertStringContainsString('if root.p1 == 0 : VerticalLayout {', $out->slint);
        self::assertStringContainsString('if root.p1 == 1 : VerticalLayout {', $out->slint);
        self::assertStringContainsString('in-out property <int> p1;', $out->slint);
    }

    /** @param list<ClassInfo> $classes  compiled with the real material + shadcn kits and the extra fixture kit */
    private function compileWith(string $kit, array $classes, string $projectRoot = ''): CompiledViews
    {
        $kits = KitRegistry::load([
            self::ROOT . '/kits/material/kit.yml',
            self::ROOT . '/kits/shadcn/kit.yml',
            self::ROOT . '/tests/fixtures/kits/extra/kit.yml',
        ], (string) realpath(self::ROOT));
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        return (new SlintCompiler($kits, $kit, $projectRoot))->compile($byName);
    }

    public function testShadcnProvidesEveryCoreElement(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $shadcn = $kits->get('shadcn');
        $material = $kits->get('material');
        foreach (['Label', 'Button', 'Entry', 'Checkbox', 'Slider', 'Select', 'Image', 'Progress', 'Card', 'Separator', 'TabBar'] as $tag) {
            self::assertArrayHasKey($tag, $shadcn->elements, $tag);
            self::assertSame(array_keys($material->elements[$tag]->props), array_keys($shadcn->elements[$tag]->props), "$tag props");
            self::assertSame($material->elements[$tag]->events, $shadcn->elements[$tag]->events, "$tag events");
            self::assertSame($material->elements[$tag]->children, $shadcn->elements[$tag]->children, "$tag children");
        }
        self::assertSame('ShadWindow', $shadcn->window);
    }

    public function testKitOnlyAttributeFailsWithAnotherKit(): void
    {
        $out = $this->compileWith('extra', [$this->screen('S', '<Button text="x" size="lg" />')]);
        self::assertStringContainsString('ExtraButton {', $out->slint);
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("S.rk:1: <Button> has no attribute 'size'");
        $this->compileWith('material', [$this->screen('S', '<Button text="x" size="lg" />')]);
    }

    public function testAShadcnAppUsesOnlyShadcn(): void
    {
        $out = $this->compileWith('shadcn', [$this->screen('S', '<VStack><Label text="Hi" /><Button text="x" /></VStack>')]);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], (string) realpath(self::ROOT));
        self::assertSame(['shadcn'], array_map(fn($kit) => $kit->name, $kits->used($out->imports)), 'so Material is never fetched for it');
    }

    public function testShadcnKitCompilesEveryElement(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler)) {
            self::markTestSkipped('slint-compiler is not installed');
        }
        $out = $this->compileWith('shadcn', [$this->screen('S', <<<XML
            <VStack>
                <Label text="{status}" size="title" />
                <Tabs selected="{tab}">
                    <Tab title="Inputs">
                        <VStack>
                            <HStack>
                                <Button text="a" onClick="press('default')" />
                                <Button text="b" variant="secondary" onClick="press('secondary')" />
                                <Button text="c" variant="outline" />
                                <Button text="d" variant="ghost" />
                                <Button text="e" variant="destructive" />
                            </HStack>
                            <Entry value="{name}" />
                            <Checkbox text="Subscribe ✓" checked="{on}" />
                            <Slider value="{vol}" min="0" max="100" />
                            <Select value="{fruit}" options="{FRUITS}" />
                        </VStack>
                    </Tab>
                    <Tab title="Display…">
                        <VStack>
                            <Image source="assets/my icon é.svg" />
                            <Progress value="{ratio}" />
                        </VStack>
                    </Tab>
                    <Tab title="Layout">
                        <Card title="Card"><Label text="x" /><Separator /><Label text="y" /></Card>
                    </Tab>
                </Tabs>
            </VStack>
            XML,
            props: ['status' => 'string', 'tab' => 'int', 'name' => 'string', 'on' => 'bool', 'vol' => 'float', 'fruit' => 'string'],
            computed: ['ratio' => 'float'],
            handlers: ['press' => ['string']],
            constants: ['FRUITS' => ['Apple', 'Dragon fruit']])], __DIR__ . '/../fixtures');
        self::assertStringContainsString('inherits ShadWindow', $out->slint);
        self::assertStringNotContainsString('rk-material', $out->slint);
        self::assertSame([], $out->libraries);
        file_put_contents("$this->dir/app.slint", $out->slint);
        exec(escapeshellarg($compiler) . ' ' . escapeshellarg("$this->dir/app.slint") . ' -f cpp --embed-resources=embed-files -o ' . escapeshellarg("$this->dir/app.h") . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output) . "\n\n" . $out->slint);
    }

    public function testDevScreensCanStayOnTop(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml'], '/rk');
        $out = (new SlintCompiler($kits, 'material', '', dev: true))->compile(['Demo\S' => $this->screen('S', '<Label text="x" />')]);
        self::assertStringContainsString("    in property <bool> rk-on-top;\n    always-on-top: root.rk-on-top;\n", $out->slint);
        self::assertStringNotContainsString('rk-on-top', $this->compile([$this->screen('T', '<Label text="x" />')])->slint);
    }

    public function testBoolInTextIsConvertedToString(): void
    {
        $out = $this->compile([$this->screen('S', '<Label text="On: {show}" />', props: ['show' => 'bool'])]);
        self::assertStringContainsString('text: "On: \\{(root.p1 ? "true" : "false")}";', $out->slint);
    }

    public function testLiteralTextIsEscaped(): void
    {
        $out = $this->compile([$this->screen('S', '<Label text="say &quot;hi&quot; \ {x" />')]);
        self::assertStringContainsString('text: "say \"hi\" \\\\ {x";', $out->slint);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalidTemplates(): iterable
    {
        yield 'unknown name' => ['<Label text="{cuont}" />', "S.rk:1: 'cuont' is not a property, method or constant of S"];
        yield 'unknown element' => ['<Lable text="x" />', 'S.rk:1: unknown element <Lable>'];
        yield 'unknown attribute' => ['<Button colour="red" />', "S.rk:1: <Button> has no attribute 'colour'"];
        yield 'bad enum' => ['<Label text="x" size="huge" />', 'S.rk:1: size="huge" must be one of: body, title, display'];
        yield 'unknown handler' => ['<Button onClick="nope" />', "S.rk:1: 'nope' is not a public method of S"];
        yield 'when on non-bool' => ['<Label when="count" text="x" />', "S.rk:1: when=\"count\": 'count' is not a bool"];
        yield 'each and when' => ['<Label each="x in list" when="flag" />', 'S.rk:1: an element can\'t have both each="…" and when="…"'];
        yield 'unknown kit prefix' => ['<VStack xmlns:ui="nope"><ui:Card /></VStack>', 'S.rk:1: xmlns "nope" is not an installed kit (installed: material, shadcn)'];
        yield 'two-way to method' => ['<Entry value="{greeting}" />', 'S.rk:1: value="{greeting}": two-way inputs must bind a property of the screen'];
        yield 'two-way in each' => ['<VStack><Entry each="x in list" value="{name}" /></VStack>', 'S.rk:1: value="{name}": two-way inputs can\'t be repeated with each= yet'];
        yield 'tab outside tabs' => ['<VStack><Tab title="x"><Label text="a" /></Tab></VStack>', 'S.rk:1: <Tab> is only allowed directly inside <Tabs>'];
        yield 'tab with two children' => ['<Tabs selected="{tab}"><Tab title="x"><Label text="a" /><Label text="b" /></Tab></Tabs>', 'S.rk:1: <Tab> must contain exactly one element (wrap several in a <VStack>)'];
        yield 'tabs with other child' => ['<Tabs selected="{tab}"><Label text="a" /></Tabs>', 'S.rk:1: <Tabs> may only contain <Tab> elements'];
        yield 'tabs selected literal' => ['<Tabs selected="0"><Tab title="x"><Label text="a" /></Tab></Tabs>', 'S.rk:1: <Tabs selected="0">: selected must bind an int property, e.g. selected="{tab}"'];
        yield 'tabs selected wrong type' => ['<Tabs selected="{flag}"><Tab title="x"><Label text="a" /></Tab></Tabs>', 'S.rk:1: selected="{flag}": expected an int property, $flag is bool'];
        yield 'untyped computed method' => ['<Label text="{untyped}" />', "S.rk:1: 'untyped()' has no return type; give it one (string, int, float, bool or array) to show it in a template"];
        yield 'computed object' => ['<Label text="{thing}" />', "S.rk:1: 'thing()' returns Demo\\Thing; templates can show string, int, float, bool or array"];
        yield 'method with arguments' => ['<Label text="{go}" />', "S.rk:1: 'go()' takes arguments; templates can only show methods without required parameters"];
        yield 'when with an expression' => ['<Label when="count > 0" text="x" />', "S.rk:1: when=\"count > 0\": expressions aren't supported; name a bool property or a computed method returning bool"];
        yield 'when with braces' => ['<Label when="{flag}" text="x" />', 'S.rk:1: when="{flag}": write the name without braces, e.g. when="flag"'];
        yield 'negated when with braces' => ['<Label when="!{flag}" text="x" />', 'S.rk:1: when="!{flag}": write the name without braces'];
        yield 'each with braces' => ['<Label each="x in {list}" text="x" />', 'S.rk:1: each="x in {list}" must look like each="item in list", where list is a list property or computed method (no braces, no expressions)'];
        yield 'tab title binding' => ['<Tabs selected="{tab}"><Tab title="{name}"><Label text="a" /></Tab></Tabs>', 'S.rk:1: title="{name}": tab titles are fixed text; bindings aren\'t supported there yet'];
        yield 'grow value' => ['<Button grow="yes" />', 'S.rk:1: grow="yes" must be "true" or "false"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTemplates')]
    public function testValidationErrors(string $rk, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile([$this->screen('S', $rk,
            props: ['count' => 'int', 'flag' => 'bool', 'list' => 'list', 'name' => 'string', 'tab' => 'int'],
            computed: ['greeting' => 'string', 'untyped' => 'mixed', 'thing' => 'Demo\\Thing'],
            handlers: ['greeting' => [], 'untyped' => [], 'thing' => [], 'go' => ['string']])]);
    }

    public function testMissingTemplateFileNamesTheDeclaredPath(): void
    {
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/nope.rk", templateDeclared: 'nope.rk');
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("S::template() points to nope.rk, which doesn't exist ($this->dir/nope.rk)");
        $this->compile([$class]);
    }

    public function testUndeclaredTemplateIsAnError(): void
    {
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, '', templateDeclared: '');
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("Demo\\S doesn't declare template()");
        $this->compile([$class]);
    }

    public function testGeneratedSlintCompiles(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        $material = self::ROOT . '/kits/material/library/material.slint';
        if (!is_file($compiler) || !\RageKit\Tests\Support\Fetched::kit('material')) {
            self::markTestSkipped('slint-compiler or the Material library is not installed');
        }
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml'], (string) realpath(self::ROOT));
        $out = (new SlintCompiler($kits, 'material'))->compile(['Demo\S' => $this->screen('S', <<<XML
            <VStack>
                <Label text="Count: {count}" size="display" />
                <Label text="Show: {show}" />
                <HStack each="row in KEYS"><Button each="k in row" text="{k}" onClick="press(k)" grow="true" /></HStack>
                <Entry value="{name}" />
                <Checkbox text="Show" checked="{show}" />
                <VStack when="show"><Label each="h in history" text="{h}" /></VStack>
                <Slider value="{vol}" min="0" max="100" />
                <Select value="{fruit}" options="{FRUITS}" />
                <Progress value="{ratio}" />
                <Card title="C"><Separator /><Button text="b" variant="destructive" /></Card>
                <Tabs selected="{tab}">
                    <Tab title="A"><Label text="a" /></Tab>
                    <Tab title="B"><VStack><Label text="b" /><Button text="x" /></VStack></Tab>
                </Tabs>
            </VStack>
            XML,
            props: ['count' => 'int', 'name' => 'string', 'show' => 'bool', 'history' => 'list', 'vol' => 'float', 'fruit' => 'string', 'tab' => 'int'],
            computed: ['ratio' => 'float'],
            handlers: ['press' => ['string']],
            constants: ['KEYS' => [['1', '2'], ['3']], 'FRUITS' => ['Apple', 'Dragon fruit']])]);
        file_put_contents("$this->dir/app.slint", $out->slint);
        exec(escapeshellarg($compiler) . ' ' . escapeshellarg("$this->dir/app.slint") . " -f cpp -L material=" . escapeshellarg($material) . ' -o ' . escapeshellarg("$this->dir/app.h") . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output) . "\n\n" . $out->slint);
    }

    public function testImageLiteralEmitsImageUrl(): void
    {
        $out = $this->compileIn(__DIR__ . '/../fixtures', [$this->screen('S', '<VStack xmlns:p="pics"><p:ImageButton image="assets/my icon é.svg" text="Go" /></VStack>')]);
        $abs = (string) realpath(__DIR__ . '/../fixtures/assets/my icon é.svg');
        self::assertStringContainsString('image: @image-url(' . Expression::slintString($abs) . ');', $out->slint);
        self::assertSame([$abs], $out->images);
    }

    public function testImageBindingIsRejected(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("image=\"{icon}\": image props take a file path; binding images to PHP values isn't supported yet");
        $this->compileIn(__DIR__ . '/../fixtures', [$this->screen('S', '<VStack xmlns:p="pics"><p:ImageButton image="{icon}" /></VStack>', props: ['icon' => 'string'])]);
    }

    public function testMissingImageFileIsRejected(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('image="assets/nope.svg": file not found');
        $this->compileIn(__DIR__ . '/../fixtures', [$this->screen('S', '<VStack xmlns:p="pics"><p:ImageButton image="assets/nope.svg" /></VStack>')]);
    }

    public function testGeneratedSlintWithImageCompiles(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        $material = self::ROOT . '/kits/material/library/material.slint';
        if (!is_file($compiler) || !\RageKit\Tests\Support\Fetched::kit('material')) {
            self::markTestSkipped('slint-compiler or the Material library is not installed');
        }
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/tests/fixtures/kits/pics/kit.yml'], (string) realpath(self::ROOT));
        $out = (new SlintCompiler($kits, 'material', __DIR__ . '/../fixtures'))->compile(['Demo\S' => $this->screen('S',
            '<VStack xmlns:p="pics"><Label text="{status}" /><p:ImageButton image="assets/my icon é.svg" text="Go" onClick="go" /><Image source="assets/my icon é.svg" /></VStack>',
            props: ['status' => 'string'], handlers: ['go' => []])]);
        file_put_contents("$this->dir/app.slint", $out->slint);
        exec(escapeshellarg($compiler) . ' ' . escapeshellarg("$this->dir/app.slint") . ' -f cpp --embed-resources=embed-files -L material=' . escapeshellarg($material) . ' -o ' . escapeshellarg("$this->dir/app.h") . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output) . "\n\n" . $out->slint);
    }
}
