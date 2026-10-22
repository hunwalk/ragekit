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
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", props: ['items' => 'list'], constants: ['STYLES' => self::STYLES]);
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
            . '<Label text="body" /><Badge text="new" style="corner" /></View>');
        file_put_contents("$this->dir/app.slint", $out->slint);
        $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '-o', "$this->dir/app.h"];
        foreach ($out->libraries as $name => $path) {
            array_push($command, '-L', "$name=$path");
        }
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
    }
}
