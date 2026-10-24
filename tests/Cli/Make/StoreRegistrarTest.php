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

final class StoreRegistrarTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const STARTER_STORE = <<<'PHP'
        <?php

        namespace App\Stores;

        use RageKit\RootStore;

        /** The app's state. */
        final class AppStore extends RootStore
        {
            public Profile $profile;

            public function __construct()
            {
                parent::__construct();
                $this->profile = new Profile();
            }
        }

        PHP;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Paths::normalize(sys_get_temp_dir() . '/rk-store-' . uniqid());
        mkdir("$this->dir/src/Stores", 0777, true);
        file_put_contents("$this->dir/project.yml", "name: app\nsources:\n  - src\n");
        file_put_contents("$this->dir/composer.json", json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]));
    }

    private function make(string $name): \RageKit\Cli\Make\MadeFiles
    {
        return (new Maker((string) realpath(self::ROOT), AppPaths::find($this->dir)))->make(MakeKind::Store, $name);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents("$this->dir/$relative");
    }

    public function testRegistersPropertyAndAssignment(): void
    {
        file_put_contents("$this->dir/src/Stores/AppStore.php", self::STARTER_STORE);
        $made = $this->make('Session');
        self::assertSame(['src/Stores/Session.php'], $made->created);
        self::assertSame(['src/Stores/AppStore.php'], $made->changed);
        self::assertSame(['templates read it as $store->session->…, PHP as AppStore::get()->session'], $made->hints);
        self::assertStringContainsString('final class Session extends Store', $this->read('src/Stores/Session.php'));
        self::assertSame(str_replace(
            ["    public Profile \$profile;\n", "        \$this->profile = new Profile();\n"],
            ["    public Profile \$profile;\n    public Session \$session;\n", "        \$this->profile = new Profile();\n        \$this->session = new Session();\n"],
            self::STARTER_STORE,
        ), $this->read('src/Stores/AppStore.php'));
    }

    public function testNoRootCreatesAppStore(): void
    {
        $made = $this->make('Session');
        self::assertSame(['src/Stores/Session.php', 'src/Stores/AppStore.php'], $made->created);
        $root = $this->read('src/Stores/AppStore.php');
        self::assertStringContainsString('final class AppStore extends RootStore', $root);
        self::assertStringContainsString('public Session $session;', $root);
        self::assertStringContainsString('$this->session = new Session();', $root);
        self::assertStringContainsString("createStore()", implode("\n", $made->hints));
    }

    /** Review Focus 2: an unusual root is left alone, and the message says what to add. */
    public function testRootWithoutConstructorIsAClearError(): void
    {
        file_put_contents("$this->dir/src/Stores/AppStore.php", "<?php\nnamespace App\\Stores;\nfinal class AppStore extends \\RageKit\\RootStore\n{\n}\n");
        try {
            $this->make('Session');
            self::fail('expected an error');
        } catch (RageKitException $e) {
            self::assertStringContainsString("couldn't add Session to src/Stores/AppStore.php (it has no constructor); add these yourself:", $e->getMessage());
            self::assertStringContainsString('public Session $session;', $e->getMessage());
            self::assertStringContainsString('$this->session = new Session();', $e->getMessage());
        }
        self::assertFileDoesNotExist("$this->dir/src/Stores/Session.php", 'nothing written');
    }

    public function testPropertyAlreadyThere(): void
    {
        file_put_contents("$this->dir/src/Stores/AppStore.php", str_replace(['Profile', 'profile'], ['Session', 'session'], self::STARTER_STORE));
        $before = $this->read('src/Stores/AppStore.php');
        try {
            $this->make('Session');
            self::fail('expected an error');
        } catch (RageKitException $e) {
            self::assertStringContainsString('(it already has $session)', $e->getMessage());
        }
        self::assertSame($before, $this->read('src/Stores/AppStore.php'));
    }

    /** The root may live anywhere under src/, in another namespace: the store is then written fully qualified. */
    public function testRootOutsideStoresFolder(): void
    {
        file_put_contents("$this->dir/src/AppStore.php", str_replace('namespace App\Stores;', 'namespace App;', self::STARTER_STORE));
        $made = $this->make('Session');
        self::assertSame(['src/AppStore.php'], $made->changed);
        self::assertStringContainsString('public \App\Stores\Session $session;', $this->read('src/AppStore.php'));
        self::assertStringContainsString('$this->session = new \App\Stores\Session();', $this->read('src/AppStore.php'));
    }

    public function testRegisteredStoreCompiles(): void
    {
        $this->make('Session');
        file_put_contents("$this->dir/src/Stores/Session.php", str_replace('{', "{\n    public string \$userName = '';", $this->read('src/Stores/Session.php')));
        (new Maker((string) realpath(self::ROOT), AppPaths::find($this->dir)))->make(MakeKind::Screen, 'Home');
        file_put_contents("$this->dir/templates/Screens/HomeScreen.rk", '<Label text="{{ $store->session->userName }}" />');
        foreach (SourceFiles::php(["$this->dir/src"]) as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines));
        }
        $classes = ClassScanner::scan(SourceFiles::php(["$this->dir/src"]), (string) realpath(self::ROOT), $this->dir, ["$this->dir/src"]);
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $out = (new SlintCompiler($kits, 'shadcn', $this->dir))->compile($classes);
        self::assertSame('App\Stores\AppStore', $out->rootStore);
    }

    /** @return iterable<string, array{0: string}> root stores make:store must refuse, not break */
    public static function oddRoots(): iterable
    {
        yield 'one-line constructor' => ["<?php\nnamespace App\\Stores;\nfinal class AppStore extends \\RageKit\\RootStore\n{\n    public Profile \$profile;\n    public function __construct() { parent::__construct(); \$this->profile = new Profile(); }\n}\n"];
        yield 'another class first' => ["<?php\nnamespace App\\Stores;\nfinal class Helper\n{\n    public int \$x = 0;\n    public function __construct()\n    {\n    }\n}\nfinal class AppStore extends \\RageKit\\RootStore\n{\n    public function __construct()\n    {\n        parent::__construct();\n    }\n}\n"];
        yield 'interface first' => ["<?php\nnamespace App\\Stores;\ninterface Thing\n{\n    public function go(\$a);\n}\nfinal class AppStore extends \\RageKit\\RootStore\n{\n    public function __construct()\n    {\n        parent::__construct();\n    }\n}\n"];
    }

    /** The result always parses: either the store is added to the right class, or nothing changes. */
    #[\PHPUnit\Framework\Attributes\DataProvider('oddRoots')]
    public function testOddRootsAreEditedRightOrRefused(string $source): void
    {
        file_put_contents("$this->dir/src/Stores/AppStore.php", $source);
        try {
            $this->make('Session');
        } catch (RageKitException $e) {
            self::assertStringContainsString('add these yourself', $e->getMessage());
            self::assertSame($source, $this->read('src/Stores/AppStore.php'));
            return;
        }
        $result = $this->read('src/Stores/AppStore.php');
        token_get_all($result, TOKEN_PARSE); // throws ParseError if broken
        $root = substr($result, (int) strpos($result, 'class AppStore'));
        self::assertStringContainsString('public Session $session;', $root);
        self::assertStringContainsString('$this->session = new Session();', $root);
    }
}
