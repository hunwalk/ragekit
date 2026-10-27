<?php

declare(strict_types=1);

namespace RageKit\Tests\Templates;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\Templates\ClassScanner;

final class ClassScannerTest extends TestCase
{
    private const APP = __DIR__ . '/../fixtures/app';

    public function testFindsComponentsWithMembers(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $classes = ClassScanner::scan(SourceFiles::php([self::APP]), $root, (string) realpath(self::APP));

        self::assertSame(['Demo\CounterScreen', 'Demo\History'], array_keys($classes));

        $screen = $classes['Demo\CounterScreen'];
        self::assertTrue($screen->isScreen);
        self::assertSame('CounterScreen', $screen->short);
        self::assertSame(Paths::normalize((string) realpath(self::APP)) . '/templates/counter.rk', $screen->template);
        self::assertSame(['count' => 'int', 'showHistory' => 'bool', 'history' => 'list', 'focusRef' => 'string', 'focusCount' => 'int', 'scrollRef' => 'string', 'scrollRow' => 'int', 'scrollCount' => 'int'], $screen->props);
        self::assertSame('string', $screen->computed['greeting']);
        self::assertSame(['string'], $screen->handlers['press']);
        self::assertSame([], $screen->handlers['increment']);
        self::assertSame([['1', '2'], ['3']], $screen->constants['KEYS']);
        self::assertSame(8, $screen->constants['LIMIT']);

        $history = $classes['Demo\History'];
        self::assertFalse($history->isScreen);
        self::assertSame(Paths::normalize((string) realpath(self::APP)) . '/ui/screens/../shared/history.rk', $history->template);
    }

    /** @return array<string, \RageKit\Cli\Templates\ClassInfo> */
    private function layouts(): array
    {
        $dir = (string) realpath(__DIR__ . '/../fixtures/layouts');
        return ClassScanner::scan(SourceFiles::php(["$dir/src"]), (string) realpath(__DIR__ . '/../..'), $dir);
    }

    public function testWindowIsDescribed(): void
    {
        $layout = $this->layouts()['Fix\AppWindow'];
        self::assertTrue($layout->isWindow);
        self::assertFalse($layout->isScreen);
        self::assertSame('Fix\TitleBarContext', $layout->context);
        self::assertSame(['return' => 'string', 'params' => [], 'required' => 0], $layout->contextMembers['title']);
        self::assertSame(['return' => 'void', 'params' => ['string'], 'required' => 0], $layout->contextMembers['refresh']);
        self::assertSame(['query' => 'string', 'maximized' => 'bool'], $layout->props);
        self::assertSame([], $layout->handlers['minimize']);
        self::assertSame([], $layout->handlers['toggleMaximize']);
        self::assertSame([], $layout->handlers['close']);
        self::assertSame([], $layout->handlers['search']);
        // The navigator's back button: onClick="back", @if (canGoBack()).
        self::assertSame([], $layout->handlers['back']);
        self::assertSame('bool', $layout->computed['canGoBack']);
        self::assertArrayNotHasKey('attach', $layout->handlers);
        self::assertArrayNotHasKey('follow', $layout->handlers);
        self::assertArrayNotHasKey('useNavigator', $layout->handlers);
        self::assertArrayNotHasKey('navigator', $layout->computed);
        self::assertArrayNotHasKey('toastTexts', $layout->computed);
        self::assertArrayNotHasKey('adopt', $layout->handlers);
        self::assertArrayNotHasKey('id', $layout->props);
        self::assertTrue($layout->constructible);
    }

    public function testScreenNamesItsWindow(): void
    {
        $screen = $this->layouts()['Fix\HomeScreen'];
        self::assertTrue($screen->isScreen);
        self::assertSame('Fix\AppWindow', $screen->window);
        self::assertContains('Fix\TitleBarContext', $screen->interfaces);
        self::assertSame(['count' => 'int', 'focusRef' => 'string', 'focusCount' => 'int', 'scrollRef' => 'string', 'scrollRow' => 'int', 'scrollCount' => 'int'], $screen->props, 'Screen::focus() / scrollTo() values are there for ref="…"');
        self::assertArrayNotHasKey('useWindow', $screen->handlers);
        self::assertArrayNotHasKey('currentWindow', $screen->handlers);
    }

    public function testStoresAreDescribed(): void
    {
        $classes = $this->layouts();
        $root = $classes['Fix\AppStore'];
        self::assertTrue($root->isRootStore);
        self::assertFalse($root->isScreen);
        self::assertSame(['session' => 'Fix\Session'], $root->props);
        $session = $classes['Fix\Session'];
        self::assertTrue($session->isStore);
        self::assertSame(['userName' => 'string'], $session->props);
        self::assertSame('bool', $session->computed['signedIn']);
        self::assertSame(['string'], $session->handlers['signIn']);
        self::assertArrayNotHasKey('on', $session->handlers);
        self::assertArrayNotHasKey('emit', $session->handlers);
        self::assertArrayNotHasKey('get', $root->computed);
    }

    /** A screen using Window itself: '' (the plain window, like no layout before). */
    public function testPlainWindowIsEmpty(): void
    {
        $dir = (string) realpath(__DIR__ . '/../fixtures/app');
        $classes = ClassScanner::scan(SourceFiles::php(["$dir/src"]), (string) realpath(__DIR__ . '/../..'), $dir);
        foreach ($classes as $class) {
            if ($class->isScreen) {
                self::assertSame('', $class->window, $class->name);
            }
        }
    }

    public function testWindowWithRequiredConstructorArgIsNotConstructible(): void
    {
        self::assertFalse($this->layouts()['Fix\ConfiguredWindow']->constructible);
    }

    public function testBrokenClassReportsPhpError(): void
    {
        $file = sys_get_temp_dir() . '/rk-broken-' . uniqid() . '.php';
        file_put_contents($file, "<?php\nnamespace X;\nfinal class Broken extends \\RageKit\\Screen { public function x(): void { $ ; }\n}\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessageMatches('/reading the app\'s component classes failed:.*syntax error/s');
        ClassScanner::scan([$file], (string) realpath(__DIR__ . '/../..'), '/proj');
    }

    public function testOutputFromAppCodeIsReported(): void
    {
        $dir = sys_get_temp_dir() . '/rk-echo-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/Noisy.php", "<?php\nnamespace N;\necho 'hello from app code';\nfinal class Noisy extends \\RageKit\\Component {}\n");
        try {
            ClassScanner::scan(["$dir/Noisy.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
            self::fail('expected an exception');
        } catch (RageKitException $e) {
            self::assertStringStartsWith("reading the app's component classes failed: unexpected output: hello from app code", $e->getMessage());
        } finally {
            unlink("$dir/Noisy.php");
            rmdir($dir);
        }
    }

    public function testLargeStderrDoesNotDeadlock(): void
    {
        $dir = sys_get_temp_dir() . '/rk-big-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/Big.php", "<?php\nnamespace N;\nfor (\$i = 0; \$i < 20000; \$i++) { fwrite(STDERR, str_repeat('x', 100) . \"\\n\"); }\nfinal class Big extends \\RageKit\\Component {}\n");
        try {
            $classes = ClassScanner::scan(["$dir/Big.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
        } finally {
            unlink("$dir/Big.php");
            rmdir($dir);
        }
        self::assertSame(['N\\Big'], array_keys($classes));
    }

    public function testFailureShowsOnlyTheFatalErrorLine(): void
    {
        $text = "PHP noise\nPHP Fatal error:  Uncaught Error: boom in /a/B.php:3\nStack trace:\n#0 {main}\n";
        self::assertSame('PHP Fatal error:  Uncaught Error: boom in /a/B.php:3', ClassScanner::failure($text, ''));
        self::assertSame('just text', ClassScanner::failure("just text\n", ''));
    }

    public function testResolvesAppTraitsAndInterfaces(): void
    {
        $dir = sys_get_temp_dir() . '/rk-app-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/HasLabel.php', "<?php\nnamespace T;\ninterface HasLabel {}\n");
        file_put_contents($dir . '/Labelled.php', "<?php\nnamespace T;\ntrait Labelled { public string \$label = 'x'; }\n");
        file_put_contents($dir . '/Kind.php', "<?php\nnamespace T;\nenum Kind: string { case A = 'a'; }\n");
        file_put_contents($dir . '/Comp.php', "<?php\nnamespace T;\nfinal class Comp extends \\RageKit\\Component implements HasLabel { use Labelled; public const K = Kind::A; }\n");
        try {
            $classes = ClassScanner::scan(SourceFiles::php([$dir]), (string) realpath(__DIR__ . '/../..'), '/proj');
        } finally {
            array_map('unlink', glob($dir . '/*.php'));
            rmdir($dir);
        }
        self::assertSame(['T\\Comp'], array_keys($classes));
        self::assertSame(['label' => 'string'], $classes['T\\Comp']->props);
    }

    public function testTemplatePathsResolveAgainstProjectRoot(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $classes = ClassScanner::scan(SourceFiles::php([self::APP]), $root, '/proj');

        self::assertSame('/proj/templates/counter.rk', $classes['Demo\CounterScreen']->template);
        self::assertSame('templates/counter.rk', $classes['Demo\CounterScreen']->templateDeclared);
        self::assertSame('/proj/ui/screens/../shared/history.rk', $classes['Demo\History']->template);
    }

    public function testAbsoluteTemplatePathIsKept(): void
    {
        $dir = sys_get_temp_dir() . '/rk-abs-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/A.php", "<?php\nnamespace Abs;\nfinal class A extends \\RageKit\\Screen { public static function template(): string { return '/abs/a.rk'; } }\n");
        $classes = ClassScanner::scan(["$dir/A.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
        self::assertSame('/abs/a.rk', $classes['Abs\A']->template);
    }

    public function testUndeclaredTemplateIsEmpty(): void
    {
        $dir = sys_get_temp_dir() . '/rk-undeclared-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/B.php", "<?php\nnamespace Und;\nfinal class B extends \\RageKit\\Screen {}\n");
        $classes = ClassScanner::scan(["$dir/B.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
        self::assertSame('', $classes['Und\B']->template);
        self::assertSame('', $classes['Und\B']->templateDeclared);
    }

    /** A class without template() uses templates/<its path under its source folder>.rk. */
    public function testDefaultTemplatePathMirrorsSrc(): void
    {
        $dir = Paths::normalize((string) realpath(__DIR__ . '/../fixtures/conventions'));
        $classes = ClassScanner::scan(SourceFiles::php(["$dir/src"]), (string) realpath(__DIR__ . '/../..'), $dir, ["$dir/src"]);
        $home = $classes['App\Screens\HomeScreen'];
        self::assertSame("$dir/templates/Screens/HomeScreen.rk", $home->template);
        self::assertTrue($home->templateDefault);
        self::assertSame('', $home->templateDeclared);
        $login = $classes['App\Screens\Auth\LoginScreen'];
        self::assertSame("$dir/templates/Screens/Auth/LoginScreen.rk", $login->template);
        self::assertTrue($login->templateDefault);
    }

    public function testDeclaredTemplateWins(): void
    {
        $dir = (string) realpath(self::APP);
        $classes = ClassScanner::scan(SourceFiles::php([self::APP]), (string) realpath(__DIR__ . '/../..'), $dir, [$dir]);
        self::assertSame(Paths::normalize($dir) . '/templates/counter.rk', $classes['Demo\CounterScreen']->template);
        self::assertFalse($classes['Demo\CounterScreen']->templateDefault);
    }

    /** Outside every source folder there's no default: the class must declare template(). */
    public function testOutsideSourceDirsNeedsTemplate(): void
    {
        $dir = Paths::normalize((string) realpath(__DIR__ . '/../fixtures/conventions'));
        $classes = ClassScanner::scan(SourceFiles::php(["$dir/src"]), (string) realpath(__DIR__ . '/../..'), $dir);
        self::assertSame('', $classes['App\Screens\HomeScreen']->template);
        self::assertFalse($classes['App\Screens\HomeScreen']->templateDefault);
    }

    /** @param array<string, string> $files name => PHP body (namespace App) @return array<string, \RageKit\Cli\Templates\ClassInfo> */
    private function scanApp(array $files): array
    {
        $dir = sys_get_temp_dir() . '/rk-coll-' . uniqid();
        mkdir($dir);
        foreach ($files as $name => $body) {
            file_put_contents("$dir/$name.php", "<?php\nnamespace App;\n$body\n");
        }
        try {
            return ClassScanner::scan(SourceFiles::php([$dir]), (string) realpath(__DIR__ . '/../..'), '/proj');
        } finally {
            array_map('unlink', glob($dir . '/*.php'));
            rmdir($dir);
        }
    }

    public function testCollectionDescribesItsItemClass(): void
    {
        $classes = $this->scanApp([
            'Devices' => "final class Devices extends \\RageKit\\Collection { public const OF = Device::class; }",
            'Device' => "final class Device { public string \$name = ''; public float \$battery = 0.0; public array \$tags = []; private string \$secret = ''; public static int \$n = 0;\n"
                . "public function label(): string { return \$this->name; } public function rename(string \$n): void {} }",
        ]);
        $devices = $classes['App\Devices'];
        self::assertTrue($devices->isCollection);
        self::assertSame('App\Device', $devices->of);
        self::assertTrue($devices->ofFound);
        self::assertSame(['name' => 'string', 'battery' => 'float', 'tags' => 'list'], $devices->props);
        self::assertSame(['label' => 'string'], $devices->computed);
        self::assertFalse($devices->isScreen);
        self::assertArrayNotHasKey('App\Device', $classes);
    }

    public function testCollectionWithoutOf(): void
    {
        $devices = $this->scanApp(['Devices' => 'final class Devices extends \RageKit\Collection {}'])['App\Devices'];
        self::assertTrue($devices->isCollection);
        self::assertSame('', $devices->of);
        self::assertFalse($devices->ofFound);
        self::assertSame([], $devices->props);
    }

    public function testCollectionOfUnknownClass(): void
    {
        $devices = $this->scanApp(['Devices' => "final class Devices extends \\RageKit\\Collection { public const OF = 'App\\\\Nope'; }"])['App\Devices'];
        self::assertSame('App\Nope', $devices->of);
        self::assertFalse($devices->ofFound);
    }
}
