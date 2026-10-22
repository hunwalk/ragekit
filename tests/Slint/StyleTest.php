<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\ScreenModel;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Slint\StyleResolver;
use RageKit\Cli\Slint\ValueCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\TemplateException;

final class StyleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const STYLES = [
        'card' => ['backgroundColor' => '#18181b', 'borderRadius' => 12, 'padding' => 16, 'gap' => 8],
        'row' => ['backgroundColor' => 'transparent', 'paddingHorizontal' => 8, 'transition' => 150],
        'selected' => ['backgroundColor' => '#27272a', 'borderColor' => 'rgba(255, 255, 255, 0.2)'],
        'title' => ['color' => '#fafafa', 'fontSize' => 20, 'fontWeight' => 'bold', 'textAlign' => 'center'],
        'wide' => ['width' => 120, 'margin' => 8, 'opacity' => 0.5],
        'half' => ['width' => '50%'],
        'grow' => ['flex' => 1],
        'typo' => ['backgroundColour' => '#000'],
        'shrink' => ['flexShrink' => 1],
        'baseline' => ['alignItems' => 'baseline'],
        'badColour' => ['backgroundColor' => 'red'],
        'corner' => ['position' => 'absolute', 'top' => 8],
    ];
    private string $dir;
    private ClassInfo $class;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-style-' . uniqid();
        mkdir($this->dir);
        $this->class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk",
            props: ['sel' => 'bool', 'progress' => 'float', 'name' => 'string'],
            constants: ['STYLES' => self::STYLES]);
    }

    private function resolve(array $attributes, string $kind = 'view'): \RageKit\Cli\Slint\StyleResult
    {
        $model = new ScreenModel($this->class);
        $element = new Element('View', null, $attributes, [], 4, 'S.rk');
        return (new StyleResolver(new ValueCompiler()))->resolve($element, new Scope($this->class, $model), $kind);
    }

    private function compile(string $rk, array $children = []): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/tests/fixtures/kits/shadcn/kit.yml'], '/rk');
        $classes = ['Demo\S' => $this->class];
        foreach ($children as $child) {
            $classes[$child->name] = $child;
        }
        return (new SlintCompiler($kits, 'shadcn'))->compile($classes);
    }

    public function testNamedStylesMergeLeftToRight(): void
    {
        $r = $this->resolve(['style' => 'card row']);
        self::assertSame('transparent', $r->view['background']);
        self::assertSame('12px', $r->view['border-radius']);
        self::assertSame('16px', $r->layout['padding-top']);
        self::assertSame('8px', $r->layout['padding-left']);
        self::assertSame('8px', $r->layout['spacing']);
        self::assertSame(150, $r->transition);
    }

    public function testConditionalStyle(): void
    {
        $r = $this->resolve(['style' => 'row', 'rk-style' => "['selected' => \$sel]"]);
        self::assertMatchesRegularExpression('/^\(root\.p\d+ \? #27272a : transparent\)$/', $r->view['background']);
        self::assertMatchesRegularExpression('/^\(root\.p\d+ \? rgba\(255, 255, 255, 0\.2\) : transparent\)$/', $r->view['border-color']);
        self::assertContains('background', $r->animated());
    }

    public function testInlineExpressionWins(): void
    {
        $r = $this->resolve(['style' => 'wide', 'width' => '{{ $progress * 200 }}', 'opacity' => '0.8']);
        self::assertMatchesRegularExpression('/^\(\(root\.p\d+ \* 200\)\) \* 1px$/', $r->box['width']);
        self::assertSame('0.8', $r->box['opacity']);
        self::assertSame(['8px', '8px', '8px', '8px'], $r->margin);
    }

    public function testPercentAndFlex(): void
    {
        self::assertSame('50%', $this->resolve(['style' => 'half'])->box['width']);
        self::assertSame('1', $this->resolve(['style' => 'grow'])->flex);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function errors(): iterable
    {
        yield 'unknown property' => [['style' => 'typo'], "S.rk:4: style 'typo': unknown property backgroundColour (did you mean backgroundColor?)"];
        yield 'unknown style' => [['style' => 'nope'], "S.rk:4: style 'nope' isn't in S::STYLES"];
        yield 'flexShrink' => [['style' => 'shrink'], "S.rk:4: style 'shrink': flexShrink isn't supported: Slint layouts can't shrink items; use flex (grow) or a fixed size"];
        yield 'baseline' => [['style' => 'baseline'], "S.rk:4: style 'baseline': alignItems 'baseline' isn't supported (use flex-start, flex-end, center or stretch)"];
        yield 'colour name' => [['style' => 'badColour'], "S.rk:4: style 'badColour': backgroundColor 'red' isn't a colour; use #rgb, #rrggbb, #rrggbbaa, rgb(), rgba() or transparent"];
        yield 'colour binding' => [['backgroundColor' => '{{ $name }}'], "S.rk:4: backgroundColor: Slint can't turn text into a colour at runtime; switch colours with @style"];
        yield 'non-bool condition' => [['rk-style' => "['row' => \$name]"], 'the condition $name is a string'];
        yield 'dynamic name' => [['rk-style' => '[$name]'], "S.rk:4: @style entries are style names ('row') or 'name' => condition"];
        yield 'conditional position' => [['rk-style' => "['corner' => \$sel]"], "S.rk:4: @style: 'corner' sets position only when \$sel; position, offsets, margin, flex and transition can't depend on a condition (use @if to show a different element)"];
        yield 'conditional flex' => [['rk-style' => "['grow' => \$sel]"], "S.rk:4: @style: 'grow' sets flex only when \$sel; position, offsets, margin, flex and transition can't depend on a condition"];
        yield 'conditional without base' => [['rk-style' => "['wide' => \$sel]"], "S.rk:4: @style: 'wide' sets width only when \$sel; give the element a width in its base style too"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(array $attributes, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->resolve($attributes);
    }

    public function testLabelTextStyles(): void
    {
        $out = $this->compile('<Label text="Hi" style="title" />');
        self::assertStringContainsString('color: #fafafa;', $out->slint);
        self::assertStringContainsString('font-size: 20px;', $out->slint);
        self::assertStringContainsString('font-weight: 700;', $out->slint);
        self::assertStringContainsString('horizontal-alignment: center;', $out->slint);
    }

    public function testKitElementBoxStylesAndMargin(): void
    {
        $out = $this->compile('<VStack><Button text="x" style="wide" /></VStack>');
        self::assertStringContainsString('width: 120px;', $out->slint);
        self::assertStringContainsString('opacity: 0.5;', $out->slint);
        self::assertMatchesRegularExpression('/HorizontalLayout \{\s+padding-top: 8px;\s+padding-right: 8px;\s+padding-bottom: 8px;\s+padding-left: 8px;/', $out->slint);
    }

    public function testKitElementRejectsVisualStyles(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("<Button> can't take backgroundColor: kit elements only accept size, margin, flex, position and opacity styles. To change how a kit draws it, copy the kit into your app with `ragekit eject <kit>`.");
        $this->compile('<VStack><Button text="x" style="card" /></VStack>');
    }

    public function testStackRejectsVisualStyles(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("<VStack> can't take backgroundColor: use a <View> for backgrounds, borders and corners");
        $this->compile('<VStack style="card"><Label text="a" /></VStack>');
    }

    public function testStackSpacingAndPadding(): void
    {
        $this->class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", constants: ['STYLES' => ['spaced' => ['gap' => 12, 'padding' => 4, 'justifyContent' => 'center']]]);
        $out = $this->compile('<VStack style="spaced"><Label text="a" /></VStack>');
        self::assertStringContainsString('spacing: 12px;', $out->slint);
        self::assertStringContainsString('padding-top: 4px;', $out->slint);
        self::assertStringContainsString('alignment: center;', $out->slint);
        self::assertStringNotContainsString('spacing: 8px;', (string) strstr($out->slint, '.length > 0 : VerticalLayout', true), 'the stack\'s default gap is replaced');
    }

    public function testTransitionAnimatesConditionalProps(): void
    {
        $this->class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", props: ['sel' => 'bool'],
            constants: ['STYLES' => ['label' => ['color' => '#a1a1aa', 'transition' => 150], 'on' => ['color' => '#fafafa']]]);
        $out = $this->compile('<Label text="x" style="label" @style([\'on\' => $sel]) />');
        self::assertStringContainsString('animate color { duration: 150ms; easing: ease-out; }', $out->slint);
    }

    public function testChildComponentUsesParentStyles(): void
    {
        file_put_contents("$this->dir/Card.rk", '<Label text="{title}" />');
        $child = new ClassInfo('Demo\Card', 'Card', "$this->dir/Card.php", false, "$this->dir/Card.rk", props: ['title' => 'string']);
        $out = $this->compile('<VStack><Card title="a" style="wide" /></VStack>', [$child]);
        self::assertMatchesRegularExpression('/Card \{[^}]*width: 120px;/s', $out->slint);
    }

    public function testChildPropNamedLikeAStyleProperty(): void
    {
        file_put_contents("$this->dir/Bar.rk", '<Label text="x" />');
        $child = new ClassInfo('Demo\Bar', 'Bar', "$this->dir/Bar.php", false, "$this->dir/Bar.rk", props: ['width' => 'int']);
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("<Bar>: its prop \$width has the name of a style property, so style can't be used on it; rename the prop");
        $this->compile('<VStack><Bar width="3" style="wide" /></VStack>', [$child]);
    }

    public function testUnstyledElementsRefuseStyleAttributes(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("<Tabs> has no attribute 'style'");
        $this->compile('<Tabs selected="{sel}" style="card"><Tab title="a"><Label text="x" /></Tab></Tabs>');
    }

    public function testFlexChildMakesStackStretch(): void
    {
        $this->class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", constants: ['STYLES' => ['grow' => ['flex' => 2]]]);
        $out = $this->compile('<HStack><Label text="a" style="grow" /><Label text="b" /></HStack>');
        self::assertStringContainsString('alignment: stretch;', $out->slint);
        self::assertStringContainsString('horizontal-stretch: 2;', $out->slint);
    }
}
