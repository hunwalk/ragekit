<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

/** <ScrollView>: what it compiles to (its behaviour under Xvfb: tests/e2e/scroll.sh). */
final class ScrollViewTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-scrollview-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function compile(string $rk, string $kit = 'shadcn'): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk",
            props: ['items' => 'list', 'anchor' => 'string', 'stick' => 'bool'], handlers: ['loadOlder' => []]);
        $root = (string) realpath(self::ROOT);
        $kits = KitRegistry::load(["$root/kits/material/kit.yml", "$root/kits/shadcn/kit.yml"], $root);
        return (new SlintCompiler($kits, $kit))->compile(['Demo\S' => $class]);
    }

    private const LIST = "<VStack grow=\"true\">\n<ScrollView%s>\n@foreach (\$items as \$item)\n<Label text=\"{{ \$item }}\" />\n@endforeach\n</ScrollView>\n</VStack>";

    public function testAPlainListScrollsFromTheTop(): void
    {
        $slint = $this->compile(sprintf(self::LIST, ''))->slint;
        self::assertMatchesRegularExpression('/(rk-scroll-\d+) := Flickable \{/', $slint);
        self::assertMatchesRegularExpression('/content-height: max\(self\.height, (rk-scroll-\d+)-items\.preferred-height\);/', $slint);
        self::assertStringContainsString('alignment: start;', $slint);
        self::assertStringContainsString('if (self.height > 0px && (!self.rk-filled || (false && rows-changed)) && self.content-y > -200px)', $slint, 'once the view is full, rows appended below never ask for older ones');
        self::assertMatchesRegularExpression('/property <length> rk-rows: (rk-scroll-\d+)-items\.preferred-height;\s+changed rk-rows =>/', $slint, 'a short list grows without changing content-height');
        self::assertMatchesRegularExpression('/\}\s+(rk-scroll-\d+)-bar := TouchArea \{\s+property <length> rk-pressed-y;/', $slint, 'the drag strip is not inside the moving thumb');
        self::assertStringContainsString('if (self.rk-content == 0px ? false : (false || (false && ', $slint, 'neither stuck nor anchored');
        self::assertSame(0, preg_match('/rk-near-top = true;\s+root\.action/', $slint), 'no onTop handler');
    }

    public function testChat(): void
    {
        $slint = $this->compile(sprintf(self::LIST, ' stickToBottom="true" anchor="{{ $anchor }}" onTop="loadOlder"'))->slint;
        self::assertStringContainsString('alignment: end;', $slint, 'a short chat rests at the bottom');
        self::assertMatchesRegularExpression('/self\.rk-content == 0px \? true : \(\(".*root\.p\d+.*" == "bottom"\) \|\| \(true && /', $slint);
        self::assertSame(2, preg_match_all('/rk-near-top = true;\s+root\.action\(\d+, ""\);/', $slint), 'onTop: on reaching the top (content-y), and while filling up (rk-ask)');
    }

    public function testBoundStickAndLiteralAnchor(): void
    {
        $slint = $this->compile(sprintf(self::LIST, ' stickToBottom="{{ $stick }}" anchor="bottom"'))->slint;
        self::assertMatchesRegularExpression('/self\.rk-content == 0px \? root\.p\d+ : \(true \|\| /', $slint);
        self::assertStringContainsString('if (self.height > 0px && (!self.rk-filled || (true && rows-changed)) && self.content-y > -200px)', $slint, 'older rows came in: ask for more');
        self::assertStringContainsString('alignment: end;', $slint);
    }

    public function testItFillsItsStack(): void
    {
        $slint = $this->compile(sprintf(self::LIST, ''))->slint;
        self::assertMatchesRegularExpression('/alignment: stretch;\s+vertical-stretch: 1;\s+Rectangle \{\s+vertical-stretch: 1;\s+clip: true;/', $slint);
        $fixed = $this->compile(sprintf(self::LIST, ' grow="false" height="200"'))->slint;
        self::assertStringContainsString('vertical-stretch: 0;', $fixed);
        self::assertStringContainsString('height: 200px;', $fixed);
    }

    public function testEachScrollViewHasItsOwnIds(): void
    {
        $slint = $this->compile('<HStack><ScrollView><Label text="a" /></ScrollView><ScrollView><Label text="b" /></ScrollView></HStack>')->slint;
        preg_match_all('/(rk-scroll-\d+) := Flickable/', $slint, $m);
        self::assertCount(2, array_unique($m[1]));
    }

    public function testAnchorMustBeTopOrBottom(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('anchor="middle" must be one of: top, bottom');
        $this->compile(sprintf(self::LIST, ' anchor="middle"'));
    }

    public function testUnknownAttribute(): void
    {
        $this->expectException(TemplateException::class);
        $this->compile(sprintf(self::LIST, ' onBottom="loadOlder"'));
    }

    public function testSlintAcceptsItWithBothKits(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler)) {
            self::markTestSkipped('needs the Slint toolchain');
        }
        if (!\RageKit\Tests\Support\Fetched::kit('material')) {
            self::markTestSkipped('needs Slint\'s Material library (fetched by the material kit)');
        }
        foreach (['shadcn', 'material'] as $kit) {
            $out = $this->compile(sprintf(self::LIST, ' stickToBottom="true" anchor="{{ $anchor }}" onTop="loadOlder"'), $kit);
            file_put_contents("$this->dir/app.slint", $out->slint);
            $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '-o', "$this->dir/app.h"];
            foreach ($out->libraries as $name => $path) {
                array_push($command, '-L', "$name=$path");
            }
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, "$kit:\n" . implode("\n", $lines));
        }
    }
}
