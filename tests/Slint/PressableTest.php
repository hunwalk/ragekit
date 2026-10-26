<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\Elements\ShortcutCompiler;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\Fetched;
use RageKit\Tests\Support\InstalledToolchain;

/**
 * <View onPress onHoverIn onHoverOut>, <ContextMenu> in a View, and <Shortcut> (in a running app:
 * tests/e2e/pressable.sh).
 */
final class PressableTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-press-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function compile(string $rk, string $kit = 'shadcn'): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk", props: ['items' => 'list', 'q' => 'string', 'focusRef' => 'string', 'focusCount' => 'int'],
            handlers: ['open' => ['string'], 'hover' => [], 'leave' => [], 'reply' => ['string'], 'find' => [], 'focus' => ['string']]);
        $root = (string) realpath(self::ROOT);
        $kits = KitRegistry::load(["$root/kits/material/kit.yml", "$root/kits/shadcn/kit.yml"], $root);
        return (new SlintCompiler($kits, $kit))->compile(['Demo\S' => $class]);
    }

    public function testViewEventsWrapTheLayoutInATouchArea(): void
    {
        $slint = $this->compile("<VStack>\n@foreach (\$items as \$i)\n<View onPress=\"open(\$i)\" onHoverIn=\"hover\" onHoverOut=\"leave\"><Label text=\"{{ \$i }}\" /></View>\n@endforeach\n</VStack>")->slint;
        self::assertMatchesRegularExpression('/Rectangle \{.*?TouchArea \{\s+width: 100%;\s+height: 100%;\s+mouse-cursor: pointer;\s+clicked => \{\s+root\.action\(\d+, "\\\{i\}"\);/s', $slint);
        self::assertMatchesRegularExpression('/changed has-hover => \{\s+if \(self\.has-hover\) \{\s+root\.action\(\d+, ""\);\s+\} else \{\s+root\.action\(\d+, ""\);/', $slint);
        self::assertMatchesRegularExpression('/TouchArea \{.*?(FlexboxLayout|VerticalLayout) \{/s', $slint, 'the layout is inside the TouchArea');
        self::assertStringNotContainsString('TouchArea', $this->compile('<View><Label text="a" /></View>')->slint, 'a plain View stays as it was');
    }

    public function testContextMenuOpensAtThePointer(): void
    {
        $slint = $this->compile("<View>\n<Label text=\"a\" />\n<ContextMenu>\n<MenuItem text=\"Reply\" onClick=\"reply('x')\" />\n<MenuItem text=\"Delete\" variant=\"destructive\" onClick=\"open('y')\" />\n</ContextMenu>\n</View>")->slint;
        self::assertMatchesRegularExpression('/pointer-event\(event\) => \{\s+if \(event\.button == PointerEventButton\.right && event\.kind == PointerEventKind\.down\) \{\s+(rk-menu-\d+)\.open\(self\.mouse-x, self\.mouse-y\);/', $slint);
        self::assertMatchesRegularExpression('/rk-menu-\d+ := ShadContextMenu \{\s+x: 0px;\s+y: 0px;\s+items: \["Reply", "Delete"\];\s+variants: \["default", "destructive"\];\s+selected\(rk-index\) => \{/', $slint);
        self::assertStringContainsString('ShadContextMenu', $slint);
        self::assertStringContainsString('RkContextMenu', $this->compile('<View><ContextMenu><MenuItem text="R" onClick="find" /></ContextMenu></View>', 'material')->slint);
    }

    public function testContextMenuOnlyDirectlyInAView(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('S.rk:1: <ContextMenu> is only allowed directly inside <View>');
        $this->compile('<VStack><ContextMenu><MenuItem text="R" onClick="find" /></ContextMenu></VStack>');
    }

    public function testOneContextMenuPerView(): void
    {
        $this->expectExceptionMessage('a <View> has one <ContextMenu>');
        $this->compile('<View><ContextMenu><MenuItem text="R" onClick="find" /></ContextMenu><ContextMenu><MenuItem text="S" onClick="find" /></ContextMenu></View>');
    }

    public function testShortcutGoesToTheWindowKeyHandler(): void
    {
        $slint = $this->compile('<VStack><Shortcut keys="Ctrl+F" onPress="find" /><Label text="a" /></VStack>')->slint;
        self::assertMatchesRegularExpression('/key-pressed\(event\) => \{\s+if \(event\.modifiers\.control && !event\.modifiers\.shift && !event\.modifiers\.alt && !event\.modifiers\.meta && \(event\.text == "f" \|\| event\.text == "F"\)\) \{\s+root\.action\(\d+, ""\);\s+return accept;\s+\}\s+if \(event\.modifiers\.alt && event\.text == Key\.LeftArrow\)/', $slint);
    }

    public function testShortcutKeys(): void
    {
        $element = new Element('Shortcut', null, [], [], 1, 'S.rk');
        self::assertSame(['!event.modifiers.control', '!event.modifiers.shift', '!event.modifiers.alt', '!event.modifiers.meta', 'event.text == Key.Escape'], ShortcutCompiler::condition('Escape', $element));
        self::assertSame(['!event.modifiers.control', '!event.modifiers.shift', 'event.modifiers.alt', '!event.modifiers.meta', 'event.text == Key.UpArrow'], ShortcutCompiler::condition('Alt+Up', $element));
        self::assertSame(['event.modifiers.control', 'event.modifiers.shift', '!event.modifiers.alt', '!event.modifiers.meta', '(event.text == "k" || event.text == "K")'], ShortcutCompiler::condition('ctrl+shift+K', $element));
        self::assertSame('event.text == Key.F5', ShortcutCompiler::condition('F5', $element)[4]);
        self::assertSame('(event.text == "+")', ShortcutCompiler::condition('Ctrl++', $element)[4]);
        self::assertSame('(event.text == "/")', ShortcutCompiler::condition('/', $element)[4]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badShortcuts(): iterable
    {
        yield 'unknown modifier' => ['<VStack><Shortcut keys="Hyper+F" onPress="find" /></VStack>', "unknown modifier 'Hyper'"];
        yield 'unknown key' => ['<VStack><Shortcut keys="Ctrl+Banana" onPress="find" /></VStack>', "'Banana' isn't a key"];
        yield 'no handler' => ['<VStack><Shortcut keys="Escape" /></VStack>', '<Shortcut> needs onPress="method"'];
        yield 'under @if' => ["<VStack>\n@if (count(\$items) > 0)\n<Shortcut keys=\"Escape\" onPress=\"find\" />\n@endif\n</VStack>", "<Shortcut> can't be inside @if or @foreach"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badShortcuts')]
    public function testShortcutErrors(string $rk, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile($rk);
    }

    /** ref="search" + Screen::focus('search'): the kit's focus-request follows the screen's focus values. */
    public function testRefFollowsScreenFocus(): void
    {
        $slint = $this->compile('<VStack><Entry value="{{ $q }}" ref="search" /><Textarea value="{{ $q }}" ref="notes" /><Button text="F" onClick="focus(\'search\')" /></VStack>')->slint;
        self::assertMatchesRegularExpression('/ShadEntry \{.*?focus-request: root\.(p\d+) == "search" \? root\.(p\d+) : 0;/s', $slint);
        self::assertMatchesRegularExpression('/ShadTextarea \{.*?focus-request: root\.p\d+ == "notes" \? root\.p\d+ : 0;/s', $slint);
        self::assertStringContainsString('RkEntry', $this->compile('<VStack><Entry value="{{ $q }}" ref="search" /></VStack>', 'material')->slint);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badRefs(): iterable
    {
        yield 'not a name' => ['<VStack><Entry value="{{ $q }}" ref="a b" /></VStack>', 'ref="a b": a name'];
        yield 'not an input' => ['<VStack><Label text="x" ref="x" /></VStack>', "<Label> has no attribute 'ref'"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRefs')]
    public function testRefErrors(string $rk, string $message): void
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
        $rk = "<VStack>\n<Shortcut keys=\"Escape\" onPress=\"find\" />\n@foreach (\$items as \$i)\n<View onPress=\"open(\$i)\" onHoverIn=\"hover\">"
            . "<Label text=\"{{ \$i }}\" /><ContextMenu><MenuItem text=\"Reply\" onClick=\"reply(\$i)\" /></ContextMenu></View>\n@endforeach\n"
            . "<Entry value=\"{{ \$q }}\" ref=\"search\" /><Textarea value=\"{{ \$q }}\" ref=\"notes\" />\n</VStack>";
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
