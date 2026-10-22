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

/** Overlays: <Dialog> (hoisted to the window), <DropdownMenu>, toasts. */
final class OverlayTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-overlay-' . uniqid();
        mkdir($this->dir);
    }

    private function screen(string $rk, string $layout = ''): ClassInfo
    {
        file_put_contents("$this->dir/S.rk", $rk);
        return new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk",
            props: ['confirm' => 'bool', 'other' => 'bool', 'name' => 'string', 'tab' => 'int', 'items' => 'list'],
            handlers: ['cancel' => [], 'remove' => [], 'both' => ['string', 'string'], 'pick' => ['int']],
            requiredParams: ['cancel' => 0, 'remove' => 0, 'both' => 2, 'pick' => 1],
            layout: $layout, interfaces: $layout === '' ? [] : ['Demo\Ctx']);
    }

    private function layout(string $rk): ClassInfo
    {
        file_put_contents("$this->dir/L.rk", $rk);
        return new ClassInfo('Demo\L', 'L', "$this->dir/L.php", false, "$this->dir/L.rk",
            props: ['about' => 'bool', 'maximized' => 'bool'], handlers: ['close' => []], requiredParams: ['close' => 0],
            isLayout: true, context: 'Demo\Ctx');
    }

    /** @param list<ClassInfo> $classes */
    private function compile(array $classes, string $kit = 'shadcn', bool $slint = false): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name] = $class;
        }
        $out = (new SlintCompiler($kits, $kit))->compile($byName);
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

    private const DIALOG = '<Dialog open="{{ $confirm }}" title="Delete {{ $name }}?">
    <Label text="This can\'t be undone." />
    <HStack>
        <Button text="Cancel" onClick="cancel" />
        <Button text="Delete" variant="destructive" onClick="remove" />
    </HStack>
</Dialog>';

    public function testDialogCompilesInSlintWithBothKits(): void
    {
        $rk = '<VStack><Label text="Body" />' . self::DIALOG . '</VStack>';
        $this->compile([$this->screen($rk)], 'shadcn', slint: true);
        $this->compile([$this->screen($rk)], 'material', slint: true);
    }

    public function testDialogIsHoistedToTheWindow(): void
    {
        $rk = '<VStack><Tabs selected="{{ $tab }}"><Tab title="A"><VStack>' . self::DIALOG . '</VStack></Tab></Tabs></VStack>';
        $out = $this->compile([$this->screen($rk)]);
        self::assertMatchesRegularExpression('/^    if root\.p\d+ : ShadDialog \{$/m', $out->slint);
        $body = strpos($out->slint, '    VerticalLayout {');
        $dialog = strpos($out->slint, ': ShadDialog {');
        self::assertNotFalse($body);
        self::assertGreaterThan($body, $dialog);
        self::assertMatchesRegularExpression('/dismissed => \{ root\.p\d+ = false; root\.action\(\d+, "0"\); \}/', $out->slint);
        $model = $out->screens[0];
        self::assertTrue($model->bindings['screen:property:confirm']->twoWay);
    }

    public function testDialogInLayoutTemplateBelongsToLayoutAndSitsUnderTheGrips(): void
    {
        $layout = $this->layout('<VStack padded="false"><WindowDrag><WindowControls /></WindowDrag><Content />'
            . '<Dialog open="{{ $about }}" title="About"><Button text="Close" onClick="close" /></Dialog></VStack>');
        $out = $this->compile([$layout, $this->screen('<Label text="Hi" />', 'Demo\L')], 'shadcn', slint: true);
        self::assertSame('layout', $out->screens[0]->bindings['layout:property:about']->owner);
        $dialog = strpos($out->slint, ': ShadDialog {');
        $grip = strpos($out->slint, 'root.rk-window("resize-');
        self::assertNotFalse($dialog);
        self::assertGreaterThan($dialog, $grip);
    }

    public function testOpenMustBindABool(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('bool');
        $this->compile([$this->screen('<VStack><Dialog open="{{ $name }}"><Label text="x" /></Dialog></VStack>')]);
    }

    public function testOpenIsRequired(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('open');
        $this->compile([$this->screen('<VStack><Dialog title="x"><Label text="x" /></Dialog></VStack>')]);
    }

    public function testDialogUnderIfIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('use open');
        $this->compile([$this->screen("<VStack>\n@if (\$other)\n" . self::DIALOG . "\n@endif\n</VStack>")]);
    }

    public function testDialogUnderForeachIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('use open');
        $this->compile([$this->screen("<VStack>\n@foreach (\$items as \$item)\n" . self::DIALOG . "\n@endforeach\n</VStack>")]);
    }

    public function testDialogInChildComponentIsAnError(): void
    {
        file_put_contents("$this->dir/Row.rk", '<VStack><Dialog open="{{ $shown }}"><Label text="x" /></Dialog></VStack>');
        $row = new ClassInfo('Demo\Row', 'Row', "$this->dir/Row.php", false, "$this->dir/Row.rk", props: ['shown' => 'bool']);
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Dialog');
        $this->compile([$row, $this->screen('<VStack><Row shown="{{ $confirm }}" /></VStack>')]);
    }

    private const MENU = '<HStack><DropdownMenu text="Actions">
    <MenuItem text="Cancel" onClick="cancel" />
    <MenuItem text="Both {{ $name }}" onClick="both(\'a\', $name)" />
    <MenuItem text="Delete" variant="destructive" onClick="remove" />
</DropdownMenu></HStack>';

    public function testDropdownCompilesInSlintWithBothKits(): void
    {
        $this->compile([$this->screen(self::MENU)], 'shadcn', slint: true);
        $this->compile([$this->screen(self::MENU)], 'material', slint: true);
    }

    public function testEachItemCallsItsHandler(): void
    {
        $out = $this->compile([$this->screen(self::MENU)]);
        $targets = array_map(fn($a) => $a->target, $out->screens[0]->actions);
        self::assertSame(['cancel', 'both', 'remove'], $targets);
        self::assertStringContainsString('variants: ["default", "default", "destructive"];', $out->slint);
        self::assertStringContainsString('variant: "outline";', $out->slint);
        self::assertMatchesRegularExpression('/selected\(rk-index\) => \{\s*if \(rk-index == 0\) \{/', $out->slint);
    }

    public function testMenuItemOutsideDropdownIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('<DropdownMenu>');
        $this->compile([$this->screen('<VStack><MenuItem text="x" onClick="cancel" /></VStack>')]);
    }

    public function testMenuItemWithoutOnClickIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('onClick');
        $this->compile([$this->screen('<VStack><DropdownMenu text="x"><MenuItem text="x" /></DropdownMenu></VStack>')]);
    }

    public function testEveryWindowHasToastsAndATimer(): void
    {
        foreach (['shadcn', 'material'] as $kit) {
            $out = $this->compile([$this->screen('<VStack><Label text="x" /></VStack>')], $kit, slint: true);
            $binding = $out->screens[0]->bindings['screen:method:toastTexts'];
            $p = 'root.' . $binding->slintName();
            self::assertSame('list', $binding->type);
            self::assertStringContainsString("for text in $p : ", $out->slint);
            self::assertStringContainsString("running: $p.length > 0;", $out->slint);
            self::assertStringContainsString('triggered => { root.action(-2, ""); }', $out->slint);
        }
    }

    public function testToastsSitAboveDialogsAndUnderTheGrips(): void
    {
        $layout = $this->layout('<VStack padded="false"><WindowDrag><WindowControls /></WindowDrag><Content /></VStack>');
        $out = $this->compile([$layout, $this->screen('<VStack>' . self::DIALOG . '</VStack>', 'Demo\L')]);
        $dialog = strpos($out->slint, ': ShadDialog {');
        $toasts = strpos($out->slint, 'for text in');
        $grip = strpos($out->slint, 'root.rk-window("resize-');
        self::assertTrue($dialog < $toasts && $toasts < $grip);
    }

    public function testNestedDialogIsDrawnAboveItsParent(): void
    {
        $rk = '<VStack><Dialog open="{{ $confirm }}" title="Outer"><Dialog open="{{ $other }}" title="Inner"><Label text="x" /></Dialog><Label text="y" /></Dialog></VStack>';
        $out = $this->compile([$this->screen($rk)], 'shadcn', slint: true);
        $outer = strpos($out->slint, 'title: "Outer"');
        $inner = strpos($out->slint, 'title: "Inner"');
        self::assertNotFalse($outer);
        self::assertGreaterThan($outer, $inner, 'later siblings draw on top');
    }

    public function testMenuItemCanUseALoopIndexNamedIndex(): void
    {
        $rk = "<VStack>\n@foreach (\$items as \$index => \$item)\n<DropdownMenu text=\"{{ \$item }}\"><MenuItem text=\"Pick\" onClick=\"pick(\$index)\" /><MenuItem text=\"Cancel\" onClick=\"cancel\" /></DropdownMenu>\n@endforeach\n</VStack>";
        $out = $this->compile([$this->screen($rk)], 'shadcn', slint: true);
        self::assertStringNotContainsString('selected(index)', $out->slint);
    }
}
