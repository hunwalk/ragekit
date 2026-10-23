<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\ScreensJson;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

/** $store->x->y in templates: window-level bindings and actions with owner 'store'. */
final class StoreCompileTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-store-' . uniqid();
        mkdir($this->dir);
    }

    private function root(): ClassInfo
    {
        return new ClassInfo('Demo\AppStore', 'AppStore', "$this->dir/AppStore.php", false, '',
            props: ['session' => 'Demo\Session', 'settings' => 'Demo\Settings'], isRootStore: true);
    }

    /** @return list<ClassInfo> */
    private function stores(): array
    {
        return [
            new ClassInfo('Demo\Session', 'Session', "$this->dir/Session.php", false, '',
                props: ['userName' => 'string', 'roles' => 'list'],
                computed: ['signedIn' => 'bool', 'signOut' => 'void'],
                handlers: ['signedIn' => [], 'signOut' => [], 'signIn' => ['string', 'int']],
                requiredParams: ['signedIn' => 0, 'signOut' => 0, 'signIn' => 1], isStore: true),
            new ClassInfo('Demo\Settings', 'Settings', "$this->dir/Settings.php", false, '',
                props: ['darkMode' => 'bool'], isStore: true),
        ];
    }

    private function screen(string $rk, array $props = ['name' => 'string'], string $short = 'Home'): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk", props: $props);
    }

    /** @param list<ClassInfo> $classes */
    private function compile(array $classes, bool $withStores = true, string $kit = 'shadcn', bool $slint = false): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ([...($withStores ? [$this->root(), ...$this->stores()] : []), ...$classes] as $class) {
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

    public function testStoreValueIsAWindowBinding(): void
    {
        $out = $this->compile([$this->screen('<Label text="Hi {{ $store->session->userName }}" />')]);
        $binding = $out->windows[0]->bindings['store:property:session.userName'];
        self::assertSame('store', $binding->owner);
        self::assertSame('string', $binding->type);
        self::assertStringContainsString('root.' . $binding->slintName(), $out->slint);
        self::assertSame('Demo\AppStore', $out->rootStore);
    }

    public function testStoreMethodInIf(): void
    {
        $out = $this->compile([$this->screen("<VStack>\n@if (\$store->session->signedIn())\n<Label text=\"In\" />\n@endif\n</VStack>")]);
        $binding = $out->windows[0]->bindings['store:method:session.signedIn'];
        self::assertSame('bool', $binding->type);
        self::assertStringContainsString('if root.' . $binding->slintName() . ' : ', $out->slint);
    }

    public function testStoreListInForeach(): void
    {
        $out = $this->compile([$this->screen("<VStack>\n@foreach (\$store->session->roles as \$role)\n<Label text=\"{{ \$role }}\" />\n@endforeach\n</VStack>")]);
        self::assertSame('list', $out->windows[0]->bindings['store:property:session.roles']->type);
    }

    public function testStoreHandlerWithArguments(): void
    {
        $out = $this->compile([$this->screen('<Button text="Go" onClick="$store->session->signIn($name, 3)" />')]);
        $action = $out->windows[0]->pages[0]->actions[0];
        self::assertSame(['store', 'session.signIn', ['string', 'int']], [$action->owner, $action->target, $action->params]);
        self::assertSame([['expr', 0], ['literal', '3']], $action->args);
        $php = BinderEmitter::emit($out->windows, $out->rootStore);
        self::assertStringContainsString('$r->session->signIn(self::part($arg, 0), 3);', $php);
    }

    public function testStoreHandlerWithoutArguments(): void
    {
        $out = $this->compile([$this->screen('<Button text="Out" onClick="$store->session->signOut()" />')]);
        self::assertStringContainsString('$r->session->signOut();', BinderEmitter::emit($out->windows, $out->rootStore));
    }

    public function testTwoWayStoreProperty(): void
    {
        $out = $this->compile([$this->screen('<Checkbox text="Dark" checked="{{ $store->settings->darkMode }}" />')]);
        $binding = $out->windows[0]->bindings['store:property:settings.darkMode'];
        self::assertTrue($binding->twoWay);
        $set = array_values(array_filter($out->windows[0]->pages[0]->actions, fn($a) => $a->kind === 'set'))[0];
        self::assertSame('store', $set->owner);
        self::assertStringContainsString("\$r->settings->darkMode = \$arg === '1';", BinderEmitter::emit($out->windows, $out->rootStore));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function errors(): iterable
    {
        yield 'unknown store' => ['<Label text="{{ $store->cart->total }}" />', "\$store->cart: AppStore has no store 'cart' (its stores: session, settings)"];
        yield 'unknown member' => ['<Label text="{{ $store->session->nick }}" />', "\$store->session->nick: 'nick' is not a property or method of Session (it has: userName, roles, signedIn(), signOut(), signIn())"];
        yield 'method without ()' => ['<Label text="{{ $store->session->signedIn ? 1 : 2 }}" />', '$store->session->signedIn is a method: write $store->session->signedIn()'];
        yield 'property with ()' => ['<Label text="{{ $store->session->userName() }}" />', '$store->session->userName is a property: write $store->session->userName'];
        yield 'shown method with args' => ['<Label text="{{ $store->session->signIn(\'a\') }}" />', "\$store->session->signIn(): methods shown in templates can't take arguments"];
        yield 'unknown handler' => ['<Button text="x" onClick="$store->session->nope()" />', "'nope' is not a public method of Session"];
        yield 'two-way method' => ['<Checkbox text="x" checked="{{ $store->session->signedIn() }}" />', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $rk, string $message): void
    {
        if ($message === '') {
            // A method can't be two-way: it's shown one-way like any other expression.
            $out = $this->compile([$this->screen($rk)]);
            self::assertFalse($out->windows[0]->bindings['store:method:session.signedIn']->twoWay);
            return;
        }
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        $this->compile([$this->screen($rk)]);
    }

    /** Review Focus 4: an app without a RootStore subclass gets a clear error. */
    public function testStoreWithoutRootStoreIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('$store needs the app\'s RootStore: declare a class that extends RageKit\RootStore with your stores as typed public properties');
        $this->compile([$this->screen('<Label text="{{ $store->session->userName }}" />')], withStores: false);
    }

    /** Review Focus 4: `store` is reserved in templates. */
    public function testPropertyNamedStoreIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("Home has a property named \$store, which templates reserve for the app's stores; rename it");
        $this->compile([$this->screen('<Label text="x" />', ['store' => 'string'])]);
    }

    public function testLoopVariableNamedStoreIsAnError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("\$store is reserved for the app's stores; name the loop variable something else");
        $this->compile([$this->screen("<VStack>\n@foreach (\$items as \$store)\n<Label text=\"x\" />\n@endforeach\n</VStack>", ['items' => 'list'])]);
    }

    public function testTwoRootStoresAreAnError(): void
    {
        $other = new ClassInfo('Demo\OtherStore', 'OtherStore', "$this->dir/OtherStore.php", false, '', isRootStore: true);
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('two classes extend RageKit\RootStore (Demo\AppStore and Demo\OtherStore); an app has one root store');
        $this->compile([$other, $this->screen('<Label text="x" />')]);
    }

    public function testChildComponentCantReadStore(): void
    {
        file_put_contents("$this->dir/Badge.rk", '<Label text="{{ $store->session->userName }}" />');
        $child = new ClassInfo('Demo\Badge', 'Badge', "$this->dir/Badge.php", false, "$this->dir/Badge.rk");
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("child components can't read \$store yet; pass the value as a property: <Badge name=\"{{ \$store->… }}\" />");
        $this->compile([$child, $this->screen('<Badge />')]);
    }

    public function testBinderReadsTheTypedRoot(): void
    {
        $out = $this->compile([$this->screen("<VStack>\n<Label text=\"{{ \$store->session->userName }}\" />\n@if (\$store->session->signedIn())\n<Button text=\"Out\" onClick=\"\$store->session->signOut()\" />\n@endif\n</VStack>")]);
        $php = BinderEmitter::emit($out->windows, $out->rootStore);
        self::assertStringContainsString('$r = \Demo\AppStore::get();', $php);
        self::assertMatchesRegularExpression('/\d+ => \$r->session->userName,/', $php);
        self::assertMatchesRegularExpression('/\d+ => \$r->session->signedIn\(\),/', $php);
        $file = "$this->dir/binder.php";
        file_put_contents($file, $php);
        exec(PHP_BINARY . ' -l ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        $json = json_decode(ScreensJson::emit($out->windows), true);
        self::assertContains(['id' => $out->windows[0]->bindings['store:property:session.userName']->id, 'owner' => 'store', 'source' => 'property', 'name' => 'session.userName', 'type' => 'string'], $json['windows'][0]['values']);
    }

    /** Screens without $store don't read the root at all (apps without stores generate what they did). */
    public function testNoStoreNoRootRead(): void
    {
        $out = $this->compile([$this->screen('<Label text="{{ $name }}" />')]);
        self::assertStringNotContainsString('::get()', BinderEmitter::emit($out->windows, $out->rootStore));
    }

    public function testCompilesWithBothKits(): void
    {
        $rk = "<VStack>\n<Label text=\"{{ \$store->session->userName }}\" />\n@if (\$store->session->signedIn())\n<Button text=\"Out\" onClick=\"\$store->session->signOut()\" />\n@endif\n<Checkbox text=\"Dark\" checked=\"{{ \$store->settings->darkMode }}\" />\n</VStack>";
        foreach (['shadcn', 'material'] as $kit) {
            $this->compile([$this->screen($rk)], kit: $kit, slint: true);
        }
    }

    /**
     * A store action written in a screen's template belongs to that page: a click the page sent
     * before the window moved on (e.g. a second "Sign in") must not run on the next page.
     */
    public function testStoreActionsInAScreenBelongToItsPage(): void
    {
        $login = $this->screen("<VStack>\n<Button text=\"In\" onClick=\"\$store->session->signIn(\$name)\" />\n<Checkbox text=\"Dark\" checked=\"{{ \$store->settings->darkMode }}\" />\n</VStack>", short: 'Login');
        $albums = $this->screen('<Label text="Albums" />', short: 'Albums');
        $out = $this->compile([$login, $albums]);
        $window = $out->windows[0];
        self::assertSame([], $window->actions, 'nothing window-level');
        $page = $window->pages[array_search('Login', array_map(fn($p) => $p->class->short, $window->pages), true)];
        self::assertSame(['call', 'set'], array_map(fn($a) => $a->kind, $page->actions));
        $php = BinderEmitter::emit($out->windows, $out->rootStore);
        $albumsHelper = substr($php, strpos($php, 'private static function dispatchAlbums'));
        $albumsHelper = substr($albumsHelper, 0, strpos($albumsHelper, "\n    }\n"));
        self::assertStringNotContainsString('signIn', $albumsHelper);
        self::assertStringNotContainsString('darkMode', $albumsHelper);
        self::assertStringContainsString('$r->session->signIn(', $php);
    }

    /** In a window's template a store action is the window's: it works on every page. */
    public function testStoreActionsInAWindowTemplateAreWindowLevel(): void
    {
        file_put_contents("$this->dir/Frame.rk", '<VStack><Button text="Out" onClick="$store->session->signOut()" /><Content /></VStack>');
        $frame = new ClassInfo('Demo\Frame', 'Frame', "$this->dir/Frame.php", false, "$this->dir/Frame.rk", isWindow: true);
        file_put_contents("$this->dir/A.rk", '<Label text="a" />');
        file_put_contents("$this->dir/B.rk", '<Label text="b" />');
        $a = new ClassInfo('Demo\A', 'A', "$this->dir/A.php", true, "$this->dir/A.rk", window: 'Demo\Frame');
        $b = new ClassInfo('Demo\B', 'B', "$this->dir/B.php", true, "$this->dir/B.rk", window: 'Demo\Frame');
        $out = $this->compile([$frame, $a, $b]);
        self::assertSame(['session.signOut'], array_map(fn($x) => $x->target, $out->windows[0]->actions));
        self::assertSame(2, substr_count(BinderEmitter::emit($out->windows, $out->rootStore), '$r->session->signOut();'));
    }
}
