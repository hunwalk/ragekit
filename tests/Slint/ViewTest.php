<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

final class ViewTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const STYLES = [
        'tags' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => 6, 'justifyContent' => 'space-between', 'alignItems' => 'center'],
        'card' => ['backgroundColor' => '#18181b', 'borderRadius' => 12, 'padding' => 16, 'borderWidth' => 1, 'borderColor' => '#27272a', 'overflow' => 'hidden'],
        'corner' => ['position' => 'absolute', 'top' => 8, 'right' => 8],
        'bottomLeft' => ['position' => 'absolute', 'bottom' => 4, 'left' => 4, 'width' => 40],
        'centered' => ['alignSelf' => 'center'],
        'sized' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'width' => 300],
        'capped' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'maxWidth' => 500],
        'hrule' => ['height' => 1, 'backgroundColor' => '#2a2a2d'],
    ];
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-view-' . uniqid();
        mkdir($this->dir);
    }

    private function compile(string $rk): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", props: ['items' => 'list', 'flag' => 'bool', 'name' => 'string'], constants: ['STYLES' => self::STYLES]);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        return (new SlintCompiler($kits, 'shadcn'))->compile(['Demo\S' => $class]);
    }

    public function testFlexProperties(): void
    {
        $out = $this->compile('<VStack><View style="tags"><Badge text="a" style="centered" /><Badge text="b" /></View></VStack>');
        foreach (['flex-direction: row;', 'flex-wrap: wrap;', 'spacing: 6px;', 'alignment: space-between;', 'cross-axis-alignment: center;', 'cross-axis-self-alignment: center;'] as $line) {
            self::assertStringContainsString($line, $out->slint);
        }
        self::assertStringContainsString('FlexboxLayout {', $out->slint);
    }

    /**
     * A wrapping View reports no width limit of its own: Slint's wrapping FlexboxLayout reports a
     * maximum of about one child's width, which capped the whole window there (glite: 472 px of 940).
     */
    public function testWrappingViewHasNoWidthLimit(): void
    {
        $wrapping = $this->compile("<VStack><View style=\"tags\">\n@foreach (\$items as \$i)\n<Badge text=\"{{ \$i }}\" />\n@endforeach\n</View></VStack>")->slint;
        self::assertMatchesRegularExpression('/Rectangle \{\s+vertical-stretch: 0;\s+max-width: 100000px;\s+FlexboxLayout \{\s+flex-direction: row;\s+flex-wrap: wrap;/', $wrapping);
        self::assertStringNotContainsString('max-width: 100000px', $this->compile('<VStack><View><Label text="a" /></View></VStack>')->slint, 'no wrap: unchanged');
        self::assertStringNotContainsString('max-width: 100000px', $this->compile('<VStack><View style="sized"><Label text="a" /></View></VStack>')->slint, 'its own width wins');
        self::assertStringNotContainsString('max-width: 100000px', $this->compile('<VStack><View style="capped"><Label text="a" /></View></VStack>')->slint, 'its own maxWidth wins');
    }

    public function testDefaultsAreReactNatives(): void
    {
        $out = $this->compile('<VStack><View><Label text="a" /></View></VStack>');
        self::assertStringContainsString('flex-direction: column;', $out->slint);
        self::assertStringContainsString('flex-wrap: no-wrap;', $out->slint);
    }

    public function testBoxVisuals(): void
    {
        $out = $this->compile('<VStack><View style="card"><Label text="a" /></View></VStack>');
        foreach (['background: #18181b;', 'border-radius: 12px;', 'border-width: 1px;', 'border-color: #27272a;', 'clip: true;', 'padding-top: 16px;'] as $line) {
            self::assertStringContainsString($line, $out->slint);
        }
    }

    public function testAbsoluteChildren(): void
    {
        $out = $this->compile('<VStack><View style="card"><Label text="body" /><Badge text="new" style="corner" /><Label text="x" style="bottomLeft" /></View></VStack>');
        $flex = strpos($out->slint, 'FlexboxLayout {');
        $badge = strpos($out->slint, 'ShadBadge {');
        self::assertGreaterThan($flex, $badge);
        self::assertMatchesRegularExpression('/FlexboxLayout \{[^{}]*(\{[^{}]*\}[^{}]*)*\}\s+HorizontalLayout \{\s+x: parent\.width - self\.width - 8px;\s+y: 8px;\s+width: self\.preferred-width;\s+height: self\.preferred-height;\s+padding: 0px;\s+ShadBadge \{/s', $out->slint, 'the badge sits outside the flex layout, in a wrapper sized to it');
        self::assertDoesNotMatchRegularExpression('/ShadBadge \{[^}]*height: self\.preferred-height;/s', $out->slint, "the badge keeps its own height");
        self::assertStringContainsString('x: parent.width - self.width - 8px;', $out->slint);
        self::assertStringContainsString('y: 8px;', $out->slint);
        self::assertStringContainsString('y: parent.height - self.height - 4px;', $out->slint);
        self::assertStringContainsString('x: 4px;', $out->slint);
    }

    public function testAbsoluteNeedsAView(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('S.rk:1: position: absolute needs a <View> parent');
        $this->compile('<VStack><Badge text="new" style="corner" /></VStack>');
    }

    /**
     * Slint's FlexboxLayout measures a child under `if` without its width, so a wrapping <Text> in an
     * @if counted as one line and its View came out too short. Inside a View such a Text is always
     * there, hidden and emptied (the kits' Text takes no room when empty); elsewhere it stays an `if`.
     */
    public function testTextUnderIfInAViewIsCollapsedNotRemoved(): void
    {
        $out = $this->compile("<VStack><View>\n@if (\$flag)\n<Text>{{ \$name }}</Text>\n@else\n<Text>none</Text>\n@endif\n@if (\$flag)\n<Badge text=\"b\" />\n@endif\n</View></VStack>")->slint;
        self::assertMatchesRegularExpression('/ShadText \{\s+text: \(root\.p\d+\) \? "\\\{root\.p\d+\}" : "";.*?visible: root\.p\d+;\s+\}/s', $out);
        self::assertMatchesRegularExpression('/ShadText \{\s+text: \(!\(root\.p\d+\)\) \? "none" : "";.*?visible: !\(root\.p\d+\);\s+\}/s', $out);
        self::assertStringNotContainsString(': ShadText {', $out, 'no if around the texts');
        self::assertMatchesRegularExpression('/if root\.p\d+ : ShadBadge \{/', $out, 'other elements keep their if');
        $plain = $this->compile("<VStack>\n@if (\$flag)\n<Text>hi</Text>\n@endif\n</VStack>")->slint;
        self::assertMatchesRegularExpression('/if root\.p\d+ : ShadText \{/', $plain, 'outside a View: unchanged');
    }

    /**
     * slint-compiler 1.18 overflows its stack on a column FlexboxLayout repeated inside another with
     * wrapping text: a plain column View in a @foreach is a VerticalLayout instead.
     */
    public function testRepeatedColumnViewIsAVerticalLayout(): void
    {
        $rk = "<VStack><View>\n@foreach (\$items as \$i)\n<View style=\"card\"><Text>{{ \$i }}</Text></View>\n@endforeach\n</View></VStack>";
        $slint = $this->compile($rk)->slint;
        self::assertMatchesRegularExpression('/for i in root\.p\d+ : Rectangle \{.*?VerticalLayout \{\s+padding-top: 16px;.*?alignment: start;/s', $slint);
        self::assertStringContainsString('FlexboxLayout {', $slint, 'the View around the loop stays a flexbox');
        $centred = $this->compile(str_replace('style="card"', 'style="tags"', $rk))->slint;
        self::assertDoesNotMatchRegularExpression('/for i in root\.p\d+ : Rectangle \{\s+[^{}]*\s+VerticalLayout/s', $centred, 'a row (or one with alignItems) stays a flexbox');
    }

    /** The same loop at run time: a column View with wrapping text inside another View (a quote in a bubble). */
    public function testNestedColumnViewIsAVerticalLayout(): void
    {
        $slint = $this->compile('<VStack><View style="card"><View><Text>{{ $name }}</Text></View><Label text="x" /></View></VStack>')->slint;
        self::assertSame(1, substr_count($slint, 'FlexboxLayout {'), 'only the outer View is a flexbox');
    }

    /** A root stack sits 16 px in from the window's edges; padded="false" lets it reach them. */
    public function testRootStackWithoutPaddingReachesTheEdges(): void
    {
        self::assertStringContainsString('padding: 16px;', $this->compile('<HStack><Label text="a" /></HStack>')->slint);
        $flush = $this->compile('<HStack padded="false"><Label text="a" /></HStack>')->slint;
        self::assertStringNotContainsString('padding: 16px;', $flush);
        self::assertStringContainsString('padding: 0px;', $flush);
    }

    public function testEmptyViewDoesNotCapItsStack(): void
    {
        // A 1 px rule in a column: no maximum width of 0 (the window couldn't be made wider).
        $slint = $this->compile('<VStack><View style="hrule" /><Label text="a" /></VStack>')->slint;
        self::assertMatchesRegularExpression('/height: 1px;\n\s*vertical-stretch: 0;\n\s*max-width: 100000px;/', $slint);
        self::assertStringNotContainsString('max-height: 100000px;', $slint); // its height is set
        // A View with content is measured by it.
        $full = $this->compile('<VStack><View style="card"><Label text="a" /></View></VStack>')->slint;
        self::assertStringNotContainsString('max-width: 100000px;', $full);
    }

    public function testRootViewFillsTheWindow(): void
    {
        $out = $this->compile('<View style="card"><Label text="a" /></View>');
        self::assertStringNotContainsString('padding: 16px;', $out->slint);
        self::assertStringContainsString('width: parent.width;', $out->slint);
        self::assertStringContainsString('height: parent.height;', $out->slint);
    }

    public function testSlintAcceptsIt(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler)) {
            self::markTestSkipped('needs the Slint toolchain');
        }
        $out = $this->compile('<View style="card">'
            . '<View style="tags"><Badge text="a" style="centered" /><Badge text="b" /></View>'
            . '<Label text="body" /><Badge text="new" style="corner" />'
            . "\n@if (\$flag)\n<Text>maybe</Text>\n@endif\n</View>");
        file_put_contents("$this->dir/app.slint", $out->slint);
        $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '-o', "$this->dir/app.h"];
        foreach ($out->libraries as $name => $path) {
            array_push($command, '-L', "$name=$path");
        }
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
    }
}
