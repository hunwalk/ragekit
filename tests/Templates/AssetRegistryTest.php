<?php

declare(strict_types=1);

namespace RageKit\Tests\Templates;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Templates\AssetRegistry;
use RageKit\Cli\Templates\ClassScanner;

final class AssetRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Paths::normalize(sys_get_temp_dir() . '/rk-assets-' . uniqid());
        mkdir("$this->dir/src/Assets", 0777, true);
        mkdir("$this->dir/assets/images", 0777, true);
        mkdir("$this->dir/assets/icons", 0777, true);
        file_put_contents("$this->dir/assets/images/logo.png", 'png');
        file_put_contents("$this->dir/assets/icons/sun.svg", '<svg/>');
        $this->app("['images' => Assets\\Images::class, 'icons' => Assets\\Icons::class]");
        $this->group('Images', "public const LOGO = 'assets/images/logo.png';");
        $this->group('Icons', "public const SUN = 'assets/icons/sun.svg';");
    }

    private function app(string $assets, string $class = 'MyApp'): void
    {
        file_put_contents("$this->dir/src/$class.php", "<?php\nnamespace App;\nfinal class $class extends \\RageKit\\App\n{\n"
            . "    protected function firstScreen(): \\RageKit\\Screen { throw new \\LogicException(); }\n"
            . "    protected function assets(): array { return $assets; }\n}\n");
    }

    private function group(string $class, string $body): void
    {
        file_put_contents("$this->dir/src/Assets/$class.php", "<?php\nnamespace App\\Assets;\nfinal class $class\n{\n    $body\n}\n");
    }

    private function registry(): AssetRegistry
    {
        $root = (string) realpath(__DIR__ . '/../..');
        return AssetRegistry::fromClasses(ClassScanner::scan(SourceFiles::php(["$this->dir/src"]), $root, $this->dir), $this->dir);
    }

    private function fails(string $message): void
    {
        try {
            $this->registry();
            self::fail('expected an error');
        } catch (RageKitException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testTemplateNames(): void
    {
        self::assertSame('logo', AssetRegistry::templateName('LOGO'));
        self::assertSame('appLogo', AssetRegistry::templateName('APP_LOGO'));
        self::assertSame('sun2x', AssetRegistry::templateName('SUN_2X'));
    }

    public function testRegistryFromApp(): void
    {
        $registry = $this->registry();
        self::assertSame(['images', 'icons'], $registry->groups());
        self::assertSame(['logo'], $registry->names('images'));
        self::assertSame("$this->dir/assets/images/logo.png", $registry->find('images', 'logo'));
        self::assertNull($registry->find('images', 'nope'));
        self::assertSame(['assets/images/logo.png', 'assets/icons/sun.svg'], $registry->paths());
        self::assertSame(["$this->dir/assets/images/logo.png", "$this->dir/assets/icons/sun.svg"], $registry->absolutePaths());
        self::assertSame(1, $registry->index('assets/icons/sun.svg'));
        self::assertSame(-1, $registry->index('nope.png'));
    }

    public function testNoAssetsIsEmpty(): void
    {
        $this->app('[]');
        self::assertSame([], $this->registry()->groups());
        self::assertSame([], AssetRegistry::empty()->paths());
    }

    public function testClassNotFound(): void
    {
        $this->app("['images' => 'App\\\\Assets\\\\Imgs']");
        $this->fails("MyApp::assets(): 'images' => App\\Assets\\Imgs: class not found");
    }

    public function testInvalidName(): void
    {
        $this->app("['9x' => Assets\\Images::class]");
        $this->fails("MyApp::assets(): '9x' is not a valid name");
    }

    public function testMissingFile(): void
    {
        $this->group('Images', "public const LOGO = 'assets/images/missing.png';");
        $this->fails("App\\Assets\\Images::LOGO: assets/images/missing.png doesn't exist");
    }

    public function testNotAPath(): void
    {
        $this->group('Images', 'public const LOGO = 5;');
        $this->fails('App\\Assets\\Images::LOGO: expected a path (string)');
    }

    public function testTwoAppsRegisteringAssets(): void
    {
        $this->app("['icons' => Assets\\Icons::class]", 'OtherApp');
        $this->fails('two apps register assets (App\\MyApp, App\\OtherApp); keep one');
    }

    public function testTwoConstantsWithOneTemplateName(): void
    {
        $this->group('Images', "public const LOGO = 'assets/images/logo.png';\n    public const logo = 'assets/images/logo.png';");
        $this->fails('App\\Assets\\Images: LOGO and logo are both $assets->images->logo in templates');
    }
}
