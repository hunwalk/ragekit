<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Make;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Make\AppPaths;
use RageKit\Cli\Make\MakeKind;
use RageKit\Cli\Make\Maker;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassScanner;

final class MakerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Paths::normalize(sys_get_temp_dir() . '/rk-make-' . uniqid());
        mkdir("$this->dir/src", 0777, true);
        file_put_contents("$this->dir/project.yml", "name: app\nsources:\n  - main.php\n  - src\n");
        file_put_contents("$this->dir/composer.json", json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]));
    }

    private function maker(): Maker
    {
        return new Maker((string) realpath(self::ROOT), AppPaths::find($this->dir));
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents("$this->dir/$relative");
    }

    public function testScreenCreatesClassAndTemplate(): void
    {
        $made = $this->maker()->make(MakeKind::Screen, 'Login');
        self::assertSame(['src/Screens/LoginScreen.php', 'templates/Screens/LoginScreen.rk'], $made->created);
        self::assertSame([], $made->changed);
        self::assertStringContainsString('namespace App\Screens;', $this->read('src/Screens/LoginScreen.php'));
        self::assertStringContainsString('final class LoginScreen extends Screen', $this->read('src/Screens/LoginScreen.php'));
        self::assertStringNotContainsString('function template', $this->read('src/Screens/LoginScreen.php'));
        self::assertStringContainsString('LoginScreen', $this->read('templates/Screens/LoginScreen.rk'));
        self::assertStringContainsString('firstScreen()', implode("\n", $made->hints));
    }

    public function testSuffixNotDoubled(): void
    {
        self::assertSame('src/Screens/LoginScreen.php', $this->maker()->make(MakeKind::Screen, 'LoginScreen')->created[0]);
        self::assertSame('src/Windows/AppWindow.php', $this->maker()->make(MakeKind::Window, 'App')->created[0]);
    }

    public function testNestedName(): void
    {
        $made = $this->maker()->make(MakeKind::Screen, 'Auth/Login');
        self::assertSame(['src/Screens/Auth/LoginScreen.php', 'templates/Screens/Auth/LoginScreen.rk'], $made->created);
        self::assertStringContainsString('namespace App\Screens\Auth;', $this->read('src/Screens/Auth/LoginScreen.php'));
    }

    public function testLowercaseIsCapitalised(): void
    {
        self::assertSame('src/Services/GithubApi.php', $this->maker()->make(MakeKind::Service, 'githubApi')->created[0]);
    }

    public function testComponentsServicesAndFlowsHaveNoSuffix(): void
    {
        self::assertSame(['src/Components/UserBadge.php', 'templates/Components/UserBadge.rk'], $this->maker()->make(MakeKind::Component, 'UserBadge')->created);
        self::assertSame(['src/Flows/LoadUser.php'], $this->maker()->make(MakeKind::Flow, 'LoadUser')->created);
        self::assertStringContainsString('public static function run(): void', $this->read('src/Flows/LoadUser.php'));
    }

    public function testAssetsClassAndRegistrationHint(): void
    {
        $made = $this->maker()->make(MakeKind::Assets, 'Images');
        self::assertSame(['src/Assets/Images.php'], $made->created);
        self::assertStringContainsString('namespace App\Assets;', $this->read('src/Assets/Images.php'));
        self::assertStringContainsString('final class Images', $this->read('src/Assets/Images.php'));
        self::assertStringContainsString("// public const LOGO = 'assets/images/logo.png';", $this->read('src/Assets/Images.php'));
        self::assertSame(["register it in your App: protected function assets(): array { return ['images' => \App\Assets\Images::class]; }"], $made->hints);
    }

    /** @return iterable<string, array{0: string}> */
    public static function badNames(): iterable
    {
        yield 'dash' => ['login-screen'];
        yield 'digit first' => ['9Lives'];
        yield 'empty segment' => ['Auth//Login'];
        yield 'empty' => [''];
    }

    /** Review Focus 4: nothing is written for an invalid name. */
    #[\PHPUnit\Framework\Attributes\DataProvider('badNames')]
    public function testInvalidNameIsRefused(string $name): void
    {
        try {
            $this->maker()->make(MakeKind::Screen, $name);
            self::fail('expected an error');
        } catch (RageKitException $e) {
            self::assertStringContainsString('is not a valid class name', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist("$this->dir/templates");
        self::assertDirectoryDoesNotExist("$this->dir/src/Screens");
    }

    /** @return iterable<string, array{0: MakeKind, 1: string, 2: string}> names that look fine but would generate broken classes */
    public static function reservedNames(): iterable
    {
        yield 'PHP keyword' => [MakeKind::Component, 'List', "'List' can't be a class name: it's a reserved word in PHP"];
        yield 'keyword, any case' => [MakeKind::Service, 'match', "'Match' can't be a class name: it's a reserved word in PHP"];
        yield 'keyword in a folder' => [MakeKind::Screen, 'Default/Login', "'Default' can't be a class name: it's a reserved word in PHP"];
        yield 'base class' => [MakeKind::Screen, 'Screen', "'Screen' can't be a class name: it's one of RageKit's base classes"];
        yield 'store base' => [MakeKind::Store, 'Store', "'Store' can't be a class name: it's one of RageKit's base classes"];
        yield 'root store' => [MakeKind::Store, 'RootStore', "'RootStore' can't be a class name: it's one of RageKit's base classes"];
        yield 'TypePHP' => [MakeKind::Component, 'Box', "'Box' can't be a class name: TypePHP reserves it"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedNames')]
    public function testReservedNamesAreRefused(MakeKind $kind, string $name, string $message): void
    {
        try {
            $this->maker()->make($kind, $name);
            self::fail('expected an error');
        } catch (RageKitException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir("$this->dir/src") ?: [], ['.', '..'])), 'nothing written');
    }

    public function testExistingFileNeedsForce(): void
    {
        $this->maker()->make(MakeKind::Screen, 'Login');
        file_put_contents("$this->dir/templates/Screens/LoginScreen.rk", 'mine');
        try {
            $this->maker()->make(MakeKind::Screen, 'Login');
            self::fail('expected an error');
        } catch (RageKitException $e) {
            self::assertStringContainsString('src/Screens/LoginScreen.php already exists (--force overwrites it)', $e->getMessage());
        }
        self::assertSame('mine', $this->read('templates/Screens/LoginScreen.rk'));
        $this->maker()->make(MakeKind::Screen, 'Login', force: true);
        self::assertNotSame('mine', $this->read('templates/Screens/LoginScreen.rk'));
    }

    /** Review Focus 1: the build rejects two classes with one short name; refuse it up front. */
    public function testShortNameCollisionIsRefused(): void
    {
        mkdir("$this->dir/src/Old");
        file_put_contents("$this->dir/src/Old/LoginScreen.php", "<?php\nnamespace App\\Old;\nfinal class LoginScreen {}\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('a class named LoginScreen already exists (App\Old\LoginScreen in src/Old/LoginScreen.php); class names must be unique');
        $this->maker()->make(MakeKind::Screen, 'Login');
    }

    public function testNoPsr4FallsBackToApp(): void
    {
        unlink("$this->dir/composer.json");
        $app = AppPaths::find($this->dir);
        self::assertSame('App', $app->namespace);
        self::assertSame('no psr-4 entry for src/ in composer.json; using App\\', $app->namespaceNote);
    }

    public function testNamespaceFromComposer(): void
    {
        file_put_contents("$this->dir/composer.json", json_encode(['autoload' => ['psr-4' => ['Acme\\Shop\\' => './src']]]));
        self::assertSame('Acme\Shop', AppPaths::find($this->dir)->namespace);
        self::assertNull(AppPaths::find($this->dir)->namespaceNote);
    }

    public function testNoProjectOrNoSrcIsAnError(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("run it in your app's folder (the one with project.yml)");
        AppPaths::find(sys_get_temp_dir());
    }

    public function testAppStubWins(): void
    {
        mkdir("$this->dir/stubs");
        file_put_contents("$this->dir/stubs/screen.rk.stub", "<Label text=\"Hello from {{ class }}\" />\n");
        $this->maker()->make(MakeKind::Screen, 'Login');
        self::assertSame("<Label text=\"Hello from LoginScreen\" />\n", $this->read('templates/Screens/LoginScreen.rk'));
    }

    /** Review Focus 3: a published stub may leave placeholders out and keep Rageblade's {{ $x }}. */
    public function testAppStubMayDropPlaceholdersAndKeepsRageblade(): void
    {
        mkdir("$this->dir/stubs");
        file_put_contents("$this->dir/stubs/screen.rk.stub", "<Label text=\"{{ \$title }} {{ greeting() }}\" />\n");
        $this->maker()->make(MakeKind::Screen, 'Login');
        self::assertSame("<Label text=\"{{ \$title }} {{ greeting() }}\" />\n", $this->read('templates/Screens/LoginScreen.rk'));
    }

    public function testAppStubUnknownPlaceholderNamesTheFile(): void
    {
        mkdir("$this->dir/stubs");
        file_put_contents("$this->dir/stubs/screen.rk.stub", "<Label text=\"{{ title }}\" />\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("stubs/screen.rk.stub: no value for {{ title }}");
        $this->maker()->make(MakeKind::Screen, 'Login');
    }

    /** What make: writes builds: a screen in a generated window, using a generated component. */
    public function testGeneratedAppBuildsViews(): void
    {
        $maker = $this->maker();
        $maker->make(MakeKind::Screen, 'Login');
        $maker->make(MakeKind::Window, 'App');
        $maker->make(MakeKind::Component, 'UserBadge');
        $maker->make(MakeKind::Service, 'GithubApi');
        $maker->make(MakeKind::Flow, 'LoadUser');
        $maker->make(MakeKind::Screen, 'Shell');
        file_put_contents("$this->dir/src/Screens/ShellScreen.php", "<?php\nnamespace App\\Screens;\nuse App\\Windows\\AppWindow;\n"
            . "final class ShellScreen extends \\RageKit\\Screen { public static function window(): string { return AppWindow::class; } }\n");
        file_put_contents("$this->dir/templates/Screens/ShellScreen.rk", "<VStack><UserBadge /></VStack>\n");
        foreach (SourceFiles::php(["$this->dir/src"]) as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines));
        }
        $classes = ClassScanner::scan(SourceFiles::php(["$this->dir/src"]), (string) realpath(self::ROOT), $this->dir, ["$this->dir/src"]);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        foreach (['shadcn', 'material'] as $kit) {
            $out = (new SlintCompiler($kits, $kit, $this->dir))->compile($classes);
            self::assertSame(['rk-screens', 'rk-screens-AppWindow'], array_map(fn($w) => $w->name, $out->windows));
            self::assertStringContainsString('"LoginScreen"', $out->slint);
        }
    }
}
