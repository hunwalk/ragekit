<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\KitRegistry;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

/** <Text>: React Native's Text with shadcn's typography variants; the text is the element's content. */
final class TextTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const VARIANTS = ['h1', 'h2', 'h3', 'h4', 'p', 'lead', 'large', 'small', 'muted', 'code', 'blockquote'];
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-text-' . uniqid();
        mkdir($this->dir);
    }

    private function compile(string $rk, string $kit = 'shadcn', bool $slint = false): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk",
            props: ['n' => 'int', 'name' => 'string']);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $out = (new SlintCompiler($kits, $kit))->compile(['Demo\S' => $class]);
        if ($slint) {
            $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
            if (!is_file($compiler)) {
                self::markTestSkipped('needs the Slint toolchain');
            }
            file_put_contents("$this->dir/app.slint", $out->slint);
            $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '-o', "$this->dir/app.h"];
            foreach ($out->libraries as $name => $path) {
                array_push($command, '-L', "$name=$path");
            }
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines));
        }
        return $out;
    }

    public function testContentBecomesText(): void
    {
        $out = $this->compile("<VStack>\n<Text variant=\"h1\">  Settings\n   page </Text>\n</VStack>");
        self::assertStringContainsString('text: "Settings page";', $out->slint);
        self::assertStringContainsString('variant: "h1";', $out->slint);
    }

    public function testExpressionsInContent(): void
    {
        $out = $this->compile('<VStack><Text>Count: {{ $n < 3 ? \'few\' : \'many\' }} &amp; {{ $name }}</Text></VStack>');
        self::assertStringContainsString('"few"', $out->slint);
        self::assertStringContainsString('"many"', $out->slint);
        self::assertStringContainsString('Count: ', $out->slint);
        self::assertStringContainsString(' & ', $out->slint);
    }

    public function testEveryVariantCompilesInSlintWithBothKits(): void
    {
        $rk = "<VStack>\n";
        foreach (self::VARIANTS as $variant) {
            $rk .= "<Text variant=\"$variant\">The $variant text</Text>\n";
        }
        $rk .= "<Text color=\"#ff0000\" textAlign=\"center\" fontSize=\"18\" fontWeight=\"bold\" width=\"200\">Styled</Text>\n</VStack>";
        $this->compile($rk, 'shadcn', slint: true);
        $this->compile($rk, 'material', slint: true);
    }

    public function testTextAttributeAndContentIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('<Text>');
        $this->compile('<VStack><Text text="a">b</Text></VStack>');
    }

    public function testChildElementInTextIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('<Text>');
        $this->compile('<VStack><Text>Hello <Label text="x" /></Text></VStack>');
    }

    public function testTextContentElsewhereStillAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("text content isn't supported");
        $this->compile('<VStack><Label>hi</Label></VStack>');
    }

    public function testUnknownVariantIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->compile('<VStack><Text variant="h7">x</Text></VStack>');
    }
}
