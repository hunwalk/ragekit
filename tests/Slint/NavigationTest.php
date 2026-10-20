<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\KitRegistry;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

/** Navigation elements: Sidebar, NavList, Breadcrumb (an event with a value), Pagination, Accordion. */
final class NavigationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-nav-' . uniqid();
        mkdir($this->dir);
    }

    private function compile(string $rk, string $kit = 'shadcn', bool $slint = false): CompiledViews
    {
        file_put_contents("$this->dir/S.rk", $rk);
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk",
            props: ['section' => 'string', 'page' => 'int', 'open' => 'int', 'name' => 'string'],
            handlers: ['goTo' => ['string'], 'jump' => ['int'], 'both' => ['string', 'string']],
            requiredParams: ['goTo' => 1, 'jump' => 1, 'both' => 2],
            constants: ['PAGES' => ['Home', 'Docs', 'Blog'], 'TRAIL' => ['Home', 'Docs', 'Install']]);
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

    private const ALL = '<HStack>
    <Sidebar title="App">
        <NavList items="{{ self::PAGES }}" selected="{{ $section }}" />
    </Sidebar>
    <VStack grow="true">
        <Breadcrumb items="{{ self::TRAIL }}" onSelect="goTo" />
        <Pagination page="{{ $page }}" pages="5" />
        <Accordion open="{{ $open }}">
            <Section title="What is it?"><Label text="A" /></Section>
            <Section title="How?"><VStack><Label text="B" /><Label text="C" /></VStack></Section>
        </Accordion>
    </VStack>
</HStack>';

    public function testEverythingCompilesInSlintWithBothKits(): void
    {
        $this->compile(self::ALL, 'shadcn', slint: true);
        $this->compile(self::ALL, 'material', slint: true);
    }

    public function testNavListAndPaginationAreTwoWay(): void
    {
        $model = $this->compile(self::ALL)->screens[0];
        self::assertTrue($model->bindings['screen:property:section']->twoWay);
        self::assertTrue($model->bindings['screen:property:page']->twoWay);
    }

    public function testBreadcrumbPassesTheClickedItem(): void
    {
        $out = $this->compile(self::ALL);
        $action = array_values(array_filter($out->screens[0]->actions, fn($a) => $a->target === 'goTo'))[0];
        self::assertSame([['arg', 'value']], $action->args);
        self::assertStringContainsString("selected(value) => { root.action({$action->id}, value); }", $out->slint);
        self::assertStringContainsString('$s->goTo($arg);', BinderEmitter::emit($out->screens));
    }

    public function testAccordion(): void
    {
        $out = $this->compile(self::ALL);
        $p = $out->screens[0]->bindings['screen:property:open']->slintName();
        self::assertTrue($out->screens[0]->bindings['screen:property:open']->twoWay);
        self::assertSame(2, substr_count($out->slint, 'ShadAccordionHeader {'));
        self::assertStringContainsString("open: root.$p == 1;", $out->slint);
        self::assertStringContainsString("if root.$p == 0 : VerticalLayout {", $out->slint);
        self::assertStringContainsString("root.$p = root.$p == 1 ? -1 : 1;", $out->slint);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function errors(): iterable
    {
        yield 'value handler with args' => ['<Breadcrumb items="{{ self::TRAIL }}" onSelect="goTo(\'x\')" />', 'onSelect passes the selected item to the method: write onSelect="goTo"'];
        yield 'value handler param type' => ['<Breadcrumb items="{{ self::TRAIL }}" onSelect="jump" />', "onSelect gives a string; jump()'s parameter is an int"];
        yield 'value handler two params' => ['<Breadcrumb items="{{ self::TRAIL }}" onSelect="both" />', 'both() must take exactly one parameter: the selected item'];
        yield 'section outside' => ['<VStack><Section title="x"><Label text="a" /></Section></VStack>', '<Section> is only allowed directly inside <Accordion>'];
        yield 'section children' => ['<Accordion open="{{ $open }}"><Section title="x"><Label text="a" /><Label text="b" /></Section></Accordion>', '<Section> must contain exactly one element'];
        yield 'open not int' => ['<Accordion open="{{ $name }}"><Section title="x"><Label text="a" /></Section></Accordion>', 'open="{name}": expected an int property, $name is string'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $rk, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile($rk);
    }
}
