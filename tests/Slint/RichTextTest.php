<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\Fetched;
use RageKit\Tests\Support\InstalledToolchain;

/** <Text markdown="true" onLink>, <Emoji> (in a running app: tests/e2e/rich-text.sh) and <ImageViewer>. */
final class RichTextTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-rich-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function compile(string $rk, string $kit = 'shadcn'): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", props: ['items' => 'list', 'body' => 'string'],
            handlers: ['open' => ['string'], 'two' => ['string', 'string']]);
        $root = (string) realpath(self::ROOT);
        $kits = KitRegistry::load(["$root/kits/material/kit.yml", "$root/kits/shadcn/kit.yml"], $root);
        return (new SlintCompiler($kits, $kit))->compile(['Demo\S' => $class]);
    }

    public function testMarkdownTextIsParsedNatively(): void
    {
        $slint = $this->compile('<VStack><Text markdown="true" onLink="open">{{ $body }}</Text></VStack>')->slint;
        self::assertMatchesRegularExpression('/ShadText \{.*?markdown: true;.*?styled: RkRich\.markdown\("\\\\\{root\.p\d+\}"\);.*?link-clicked\(value\) => \{ root\.action\(\d+, value\); \}/s', $slint);
        self::assertStringContainsString('pure callback markdown(string) -> styled-text;', $slint);
        self::assertStringNotContainsString('RkRich.markdown', $this->compile('<VStack><Text>{{ $body }}</Text></VStack>')->slint);
    }

    /** StyledText takes presses on its text: under a View's ContextMenu the kit opens the menu itself. */
    public function testMarkdownTextUnderAContextMenuOpensIt(): void
    {
        $slint = $this->compile('<VStack><View><Text markdown="true" onLink="open">{{ $body }}</Text><ContextMenu><MenuItem text="Copy" onClick="open(\'x\')" /></ContextMenu></View></VStack>')->slint;
        self::assertMatchesRegularExpression('/(rk-menu-\d+)-area := TouchArea \{.*?menu: true;\s+menu-requested\(x, y\) => \{ \1\.open\(x - \1-area\.absolute-position\.x, y - \1-area\.absolute-position\.y\); \}\s+forward-click\(x, y\) => \{ RkRich\.click\(x, y\); \}/s', $slint);
        self::assertStringNotContainsString('menu: true;', $this->compile('<VStack><View><Text markdown="true">{{ $body }}</Text></View></VStack>')->slint);
    }

    public function testEmojiRepeatsTheNativeImages(): void
    {
        $slint = $this->compile('<VStack><Emoji text="{{ $body }}" size="32" /></VStack>')->slint;
        self::assertMatchesRegularExpression('/for (rk-emoji-\d+) in RkEmoji\.images\("\\\\\{root\.p\d+\}", 32px\) : Image \{\s+source: \1;\s+width: 32px;\s+height: 32px;/', $slint);
        self::assertStringContainsString('RkEmoji.images("👍", 20px)', $this->compile('<VStack><Emoji text="👍" /></VStack>')->slint);
    }

    public function testImageViewerZoomsInSlint(): void
    {
        $slint = $this->compile('<VStack><ImageViewer source="{{ $body }}" width="300" height="200" /></VStack>')->slint;
        self::assertMatchesRegularExpression('/(rk-viewer-\d+)-box := Rectangle \{.*?width: 300px;.*?property <image> rk-source: root\.p\d+;.*?\1 := Flickable \{.*?scroll-event\(event\) => \{.*?double-clicked => \{/s', $slint);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badTemplates(): iterable
    {
        yield 'no text' => ['<VStack><Emoji size="20" /></VStack>', '<Emoji> needs text="…"'];
        yield 'viewer without source' => ['<VStack><ImageViewer /></VStack>', '<ImageViewer> needs source="…"'];
        yield 'bad size' => ['<VStack><Emoji text="👍" size="big" /></VStack>', 'a number of pixels'];
        yield 'link handler takes two' => ['<VStack><Text markdown="true" onLink="two">x</Text></VStack>', 'exactly one parameter'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTemplates')]
    public function testErrors(string $rk, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile($rk);
    }

    public function testSlintAcceptsItWithBothKits(): void
    {
        $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
        if (!is_file($compiler) || !Fetched::kit('material')) {
            self::markTestSkipped('needs the Slint toolchain and Slint\'s Material library');
        }
        $rk = "<VStack>\n@foreach (\$items as \$i)\n<View><Text markdown=\"true\" onLink=\"open\">{{ \$i }}</Text><Emoji text=\"{{ \$i }}\" /><ImageViewer source=\"{{ \$i }}\" height=\"100\" /><ContextMenu><MenuItem text=\"Open\" onClick=\"open(\$i)\" /></ContextMenu></View>\n@endforeach\n</VStack>";
        foreach (['shadcn', 'material'] as $kit) {
            $out = $this->compile($rk, $kit);
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
