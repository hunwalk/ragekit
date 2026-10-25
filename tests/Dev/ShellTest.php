<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Dev\DevViews;
use RageKit\Cli\Dev\ShellBuilder;
use RageKit\Cli\Project\BackendModule;
use RageKit\Cli\Project\Project;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\ViewProject;
use RageKit\Cli\Toolchain\CppBuild;
use Symfony\Component\Console\Output\NullOutput;
use RageKit\Tests\Support\InstalledToolchain;

/** The dev shell, run headless (Slint's testing backend) against a fake PHP app. */
final class ShellTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const FAKE_APP = __DIR__ . '/../fixtures/devshell/fake_app.php';

    /** @var array{0: string, 1: array<string, string>}|null */
    private static ?array $built = null;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-shell-' . uniqid();
        mkdir($this->dir);
    }

    public function testOpenSetReloadRestartAdoptAndQuit(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'normal');
        $this->waitFor($log, "opened 1\n");
        fwrite($this->stdin, "reload\n");
        $this->waitFor($log, "reload\n");
        fwrite($this->stdin, "restart-php\n");
        $this->waitFor($log, "opened 1\n", 2);
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
        $shellLog = (string) file_get_contents("$this->dir/shell.log");
        self::assertStringContainsString('adopted window 1 (rk-screens)', $shellLog);
        self::assertStringContainsString('reloaded', $shellLog);
        self::assertStringNotContainsString('error', strtolower($shellLog));
    }

    public function testFramedWindowTakesWindowCommands(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'framed');
        $this->waitFor($log, "opened 1\n");
        $this->waitFor("$this->dir/shell.log", "php ready\n");
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
        $shellLog = (string) file_get_contents("$this->dir/shell.log");
        self::assertStringContainsString('opened window 1 (rk-screens-L)', $shellLog);
        self::assertStringContainsString('maximize 1 1', $shellLog);
        self::assertStringContainsString('minimize 1', $shellLog);
        self::assertStringNotContainsString('error', strtolower($shellLog));
    }

    public function testViewScreenLoadsInTheInterpreter(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'view');
        $this->waitFor($log, "opened 1\n");
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
        $shellLog = (string) file_get_contents("$this->dir/shell.log");
        self::assertStringContainsString('opened window 1 (rk-screens)', $shellLog);
        self::assertStringNotContainsString('error', strtolower($shellLog));
    }

    /** set-rows: a collection's struct rows and a list shown as images, updated in place. */
    public function testSetRows(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'rows');
        $this->waitFor($log, "opened 1\n");
        $this->waitFor("$this->dir/shell.log", "rows 1 p6 3\n");
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
        $shellLog = (string) file_get_contents("$this->dir/shell.log");
        self::assertStringContainsString("rows 1 p3 2\nrows 1 p3 1\nrows 1 p5 1\nrows 1 p5 0\nrows 1 p6 3\n", $shellLog);
        self::assertStringNotContainsString('error', strtolower($shellLog));
    }

    public function testUnknownScreenAnswersZero(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'nope');
        $this->waitFor($log, "opened 0\n");
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
    }

    public function testPhpFailingBeforeTheFirstWindowWaitsForARestart(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'exit3');
        $this->waitFor("$this->dir/shell.log", "php stopped 3\n");
        self::assertTrue(proc_get_status($proc)['running'], 'the shell keeps running');
        fwrite($this->stdin, "restart-php\n");
        $this->waitFor("$this->dir/shell.log", "php stopped 3\n", 2);
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
    }

    public function testPhpExitWithNoWindowsEndsShellWithItsCodeOnceAWindowWasOpen(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $proc = $this->start($shell, $entries, "$this->dir/app.log", 'open-exit3');
        self::assertSame(3, $this->exitCode($proc), 'stdin stays open, as under ragekit dev');
    }

    public function testEventWhilePhpIsDownIsDropped(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'normal');
        $this->waitFor($log, "opened 1\n");
        $this->waitFor("$this->dir/shell.log", "php ready\n");
        fwrite($this->stdin, "restart-php\nclick 1 7 lost\n");
        $this->waitFor("$this->dir/shell.log", "php ready\n", 2);
        fwrite($this->stdin, "click 1 7 kept\n");
        $this->waitFor($log, "event 1 7 kept\n");
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
        self::assertStringNotContainsString('lost', (string) file_get_contents($log));
    }

    public function testModuleCalls(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $lib = $this->buildDemoModule();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'module', ['--module', "demo=$lib"]);
        self::assertSame(0, $this->exitCode($proc));
        self::assertSame([
            "result|a\tba\tb",
            'error|unknown function demo_nope',
            'result|1',
            'result|0',
            'result|',
        ], file($log, FILE_IGNORE_NEW_LINES));
    }

    /** Headless, Slint's time is mocked: `advance <ms>` on stdin moves it on. */
    public function testTimers(): void
    {
        [$shell, $entries] = $this->buildShellAndEntries();
        $log = "$this->dir/app.log";
        $proc = $this->start($shell, $entries, $log, 'timer');
        $this->waitFor("$this->dir/shell.log", "php ready\n");
        fwrite($this->stdin, "advance 0\n");
        $this->waitFor($log, "module-event @timer 2 tick \n");
        for ($i = 1; $i <= 3; $i++) {
            fwrite($this->stdin, "advance 20\n");
            $this->waitFor($log, "module-event @timer 1 tick \n", $i);
        }
        $this->waitFor("$this->dir/shell.log", "timer 1 stopped\n");
        fwrite($this->stdin, "advance 100\nrestart-php\n");
        $this->waitFor("$this->dir/shell.log", "php ready\n", 2);
        fwrite($this->stdin, "stop\n");
        fclose($this->stdin);
        self::assertSame(0, proc_close($proc));
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        self::assertSame(1, count(array_keys($lines, 'module-event @timer 2 tick ', true)), 'the one-shot fired once');
        self::assertNotContains('module-event @timer 3 tick ', $lines, 'stopped before it fired');
        self::assertSame(3, count(array_keys($lines, 'module-event @timer 1 tick ', true)), 'nothing after stop');
        $shellLog = (string) file_get_contents("$this->dir/shell.log");
        self::assertStringContainsString("timer 1 started\n", $shellLog);
        self::assertSame(2, substr_count($shellLog, 'timer 1 started'), 'the restarted PHP started its own');
    }

    /** The demo test module's library (tests/fixtures/modules/demo), built once. */
    private function buildDemoModule(): string
    {
        static $lib = null;
        if ($lib !== null) {
            return $lib;
        }
        $dir = sys_get_temp_dir() . '/rk-shellmod-' . uniqid();
        mkdir($dir);
        $root = (string) realpath(self::ROOT);
        $m = \RageKit\Cli\Modules\Module::load("$root/tests/fixtures/modules/demo/module.yml", $root);
        file_put_contents("$dir/rk_module_demo.h", \RageKit\Cli\Modules\ModuleEmitter::header($m));
        file_put_contents("$dir/rk_module_demo_call.cc", \RageKit\Cli\Modules\ModuleEmitter::callEntry($m));
        $toolchain = InstalledToolchain::toolchain();
        $build = $toolchain->compiler()->sharedLibrary(new CppBuild(
            'rk_module_demo', $dir, ["$root/tests/fixtures/modules/demo/native/demo.cc", "$dir/rk_module_demo_call.cc"], [$dir, "$root/native/include"], [], [], 'c++17',
        ));
        self::assertSame(0, $toolchain->run($build->command, $dir), 'building the demo module');
        return $lib = $build->output;
    }

    /** @var resource */
    private $stdin;

    /** @param array<string, string> $entries */
    private function start(string $shell, array $entries, string $log, string $mode, array $extra = [])
    {
        $command = [$shell, '--headless', '--log', "$this->dir/shell.log", ...$extra];
        foreach ($entries as $name => $entry) {
            array_push($command, '--screen', "$name=$entry");
        }
        array_push($command, '--', PHP_BINARY, self::FAKE_APP, $log, $mode);
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        self::assertIsResource($proc);
        $this->stdin = $pipes[0];
        return $proc;
    }

    /** @return array{0: string, 1: array<string, string>} the shell binary and the S screen's entry */
    private function buildShellAndEntries(): array
    {
        InstalledToolchain::skipWithoutDevShell();
        if (self::$built !== null) {
            return self::$built;
        }
        $project = sys_get_temp_dir() . '/rk-shellproj-' . uniqid();
        mkdir($project);
        file_put_contents("$project/project.yml", "name: shelltest\nragekit:\n  backend: slint\n");
        file_put_contents("$project/S.rk", '<VStack><Label text="{name}" /></VStack>');
        file_put_contents("$project/F.rk", '<Label text="framed" />');
        file_put_contents("$project/L.rk", '<VStack><WindowDrag><WindowControls /></WindowDrag><Content /></VStack>');
        file_put_contents("$project/W.rk", "<VStack>\n@foreach (\$devices as \$d)\n<HStack>\n<Label text=\"{{ \$d->name }}\" />\n<Image source=\"{{ \$d->image }}\" />\n<Progress value=\"{{ \$d->level }}\" />\n</HStack>\n@endforeach\n@foreach (\$photos as \$p)\n<Image source=\"{{ \$p }}\" />\n@endforeach\n</VStack>");
        file_put_contents("$project/V.rk", "<View style=\"box\">\n@foreach (\$tags as \$tag)\n<Label text=\"{{ \$tag }}\" />\n@endforeach\n<Label text=\"corner\" style=\"corner\" />\n</View>");
        $root = (string) realpath(self::ROOT);
        $context = new BuildContext(Project::load($project), BackendModule::find($root, 'slint', InstalledToolchain::dir()), InstalledToolchain::toolchain(), new NullOutput());
        $shell = ShellBuilder::ensure($context, "$project/build/dev")['path'];
        $class = new ClassInfo('Demo\\S', 'S', "$project/S.php", true, "$project/S.rk", ['name' => 'string']);
        $framed = new ClassInfo('Demo\\F', 'F', "$project/F.php", true, "$project/F.rk", window: 'Demo\\L');
        $frame = new ClassInfo('Demo\\L', 'L', "$project/L.php", false, "$project/L.rk", isWindow: true);
        $kits = KitRegistry::load(["$root/kits/material/kit.yml"], $root);
        $view = new ClassInfo('Demo\\V', 'V', "$project/V.php", true, "$project/V.rk", ['tags' => 'list'], constants: ['STYLES' => [
            'box' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => 4, 'backgroundColor' => '#111111', 'padding' => 8],
            'corner' => ['position' => 'absolute', 'top' => 0, 'right' => 0],
        ]]);
        $devices = new ClassInfo('Demo\\Devices', 'Devices', "$project/Devices.php", false, '', isCollection: true, of: 'Demo\\Device', ofFound: true,
            props: ['name' => 'string', 'image' => 'string', 'level' => 'float']);
        $rows = new ClassInfo('Demo\\W', 'W', "$project/W.php", true, "$project/W.rk", ['devices' => 'Demo\\Devices', 'photos' => 'list']);
        $things = new ClassInfo('Demo\\Things', 'Things', "$project/Things.php", false, '', isCollection: true, of: 'Demo\\Thing', ofFound: true, props: ['name' => 'string']);
        $empty = new ClassInfo('Demo\\X', 'X', "$project/X.php", true, "$project/X.rk", ['things' => 'Demo\\Things']);
        file_put_contents("$project/X.rk", "<VStack>\n@foreach (\$things as \$i => \$t)\n<Label text=\"row {{ \$i }}\" />\n@endforeach\n</VStack>");
        $classes = ['Demo\\Things' => $things, 'Demo\\X' => $empty, 'Demo\\S' => $class, 'Demo\\F' => $framed, 'Demo\\L' => $frame, 'Demo\\V' => $view, 'Demo\\Devices' => $devices, 'Demo\\W' => $rows];
        $out = DevViews::write(new ViewProject($classes, $kits, 'material', "$project/build/dev", $project), "$project/build/dev");
        return self::$built = [$shell, $out->entries];
    }

    /** Waits for the process to end without closing its stdin (proc_close would, which reads as "stop"). */
    private function exitCode($proc, float $timeout = 15.0): int
    {
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(50_000);
        }
        self::fail('the shell did not exit');
    }

    private function waitFor(string $file, string $line, int $count = 1, float $timeout = 15.0): void
    {
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            clearstatcache();
            if (substr_count((string) @file_get_contents($file), $line) >= $count) {
                self::assertTrue(true);
                return;
            }
            usleep(50_000);
        }
        self::fail("waited for " . json_encode($line) . " x$count in $file:\n" . @file_get_contents($file) . "\nshell log:\n" . @file_get_contents("$this->dir/shell.log"));
    }
}
