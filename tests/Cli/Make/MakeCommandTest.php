<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Make;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Application;
use RageKit\Cli\Command\MakeCommand;
use RageKit\Cli\Command\StubPublishCommand;
use RageKit\Cli\Make\MakeKind;
use RageKit\Cli\Make\Maker;
use RageKit\Cli\Project\Paths;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeCommandTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private string $dir;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->dir = Paths::normalize(sys_get_temp_dir() . '/rk-makecmd-' . uniqid());
        mkdir("$this->dir/src", 0777, true);
        file_put_contents("$this->dir/project.yml", "name: app\nsources:\n  - src\n");
        file_put_contents("$this->dir/composer.json", json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]));
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
    }

    private function tester(MakeKind $kind): CommandTester
    {
        return new CommandTester(new MakeCommand((string) realpath(self::ROOT), $kind));
    }

    public function testMakeScreenPrintsFilesAndHint(): void
    {
        $tester = $this->tester(MakeKind::Screen);
        self::assertSame(0, $tester->execute(['name' => 'Login']));
        $out = $tester->getDisplay();
        self::assertStringContainsString('ragekit: created src/Screens/LoginScreen.php, templates/Screens/LoginScreen.rk', $out);
        self::assertStringContainsString('show it: return new LoginScreen();', $out);
        self::assertFileExists("$this->dir/src/Screens/LoginScreen.php");
    }

    public function testMakeStorePrintsTheChangedRoot(): void
    {
        $this->tester(MakeKind::Store)->execute(['name' => 'Profile']);
        $tester = $this->tester(MakeKind::Store);
        self::assertSame(0, $tester->execute(['name' => 'Session']));
        self::assertStringContainsString('ragekit: created src/Stores/Session.php; changed src/Stores/AppStore.php', $tester->getDisplay());
    }

    public function testAsksForTheName(): void
    {
        $tester = $this->tester(MakeKind::Window);
        $tester->setInputs(['App']);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Name of the window', $tester->getDisplay());
        self::assertFileExists("$this->dir/src/Windows/AppWindow.php");
    }

    public function testNonInteractiveWithoutNameFails(): void
    {
        $tester = $this->tester(MakeKind::Screen);
        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('give a name: ragekit make:screen <Name>', $tester->getDisplay());
    }

    /** Review Focus 4: outside an app nothing is written and the error says where to run it. */
    public function testOutsideAnAppFails(): void
    {
        unlink("$this->dir/project.yml");
        $tester = $this->tester(MakeKind::Screen);
        self::assertSame(1, $tester->execute(['name' => 'Login']));
        self::assertStringContainsString("ragekit: run it in your app's folder (the one with project.yml)", $tester->getDisplay());
        self::assertDirectoryDoesNotExist("$this->dir/src/Screens");
    }

    public function testErrorsPrintOneLineAndFail(): void
    {
        $tester = $this->tester(MakeKind::Screen);
        self::assertSame(1, $tester->execute(['name' => '9Lives']));
        self::assertStringContainsString("ragekit: '9Lives' is not a valid class name", $tester->getDisplay());
    }

    public function testForceOverwrites(): void
    {
        $this->tester(MakeKind::Flow)->execute(['name' => 'LoadUser']);
        file_put_contents("$this->dir/src/Flows/LoadUser.php", 'mine');
        self::assertSame(1, $this->tester(MakeKind::Flow)->execute(['name' => 'LoadUser']));
        self::assertSame(0, $this->tester(MakeKind::Flow)->execute(['name' => 'LoadUser', '--force' => true]));
        self::assertNotSame('mine', file_get_contents("$this->dir/src/Flows/LoadUser.php"));
    }

    public function testStubPublishCopiesAndSkips(): void
    {
        mkdir("$this->dir/stubs");
        file_put_contents("$this->dir/stubs/screen.rk.stub", 'mine');
        $tester = new CommandTester(new StubPublishCommand((string) realpath(self::ROOT)));
        self::assertSame(0, $tester->execute([]));
        foreach (Maker::stubNames() as $name) {
            self::assertFileExists("$this->dir/stubs/$name");
        }
        self::assertSame('mine', file_get_contents("$this->dir/stubs/screen.rk.stub"));
        self::assertStringContainsString('skipped stubs/screen.rk.stub (exists; --force overwrites)', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--force' => true]));
        self::assertNotSame('mine', file_get_contents("$this->dir/stubs/screen.rk.stub"));
    }

    public function testListShowsMakeCommands(): void
    {
        $app = new Application((string) realpath(self::ROOT));
        foreach (['make:screen', 'make:window', 'make:component', 'make:store', 'make:service', 'make:flow', 'stub:publish'] as $name) {
            self::assertTrue($app->has($name), $name);
        }
    }
}
