<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Toolchain\Platform;
use RageKit\Tests\Support\InstalledToolchain;

/**
 * `ragekit dev --headless` end to end (the dev shell on Slint's testing backend) on a small
 * project with its own kit. The project lives at a fixed temp path so the built shell is reused.
 */
final class DevCommandTest extends TestCase
{
    private string $project;
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $out = '';
    private string $err = '';

    protected function setUp(): void
    {
        InstalledToolchain::skipWithoutDevShell();
        $this->project = sys_get_temp_dir() . '/rk-devcommand-fixture';
        foreach (['src', 'templates', 'kits/k'] as $dir) {
            @mkdir("$this->project/$dir", 0777, true);
        }
        @unlink("$this->project/build/dev/state.json");
        file_put_contents("$this->project/project.yml", "name: devtest\nsources:\n  - main.php\n  - src\nragekit:\n  backend: slint\n");
        file_put_contents("$this->project/main.php", self::main(false));
        file_put_contents("$this->project/src/S.php", self::screen('public int $count = 0;'));
        file_put_contents("$this->project/templates/S.rk", '<VStack xmlns:k="k"><Label text="{count}" /><k:Box /></VStack>');
        file_put_contents("$this->project/kits/k/kit.yml", "name: k\nentry: k.slint\nelements:\n  Box:\n    component: KBox\n");
        file_put_contents("$this->project/kits/k/k.slint", self::KIT);
    }

    protected function tearDown(): void
    {
        if (isset($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private const KIT = "export component KBox inherits Rectangle { height: 10px; }\n";

    private static function screen(string $property): string
    {
        return "<?php\nnamespace Demo;\nfinal class S extends \\RageKit\\Screen {\n    $property\n"
            . "    public static function template(): string { return 'templates/S.rk'; }\n}\n";
    }

    public function testKitErrorKeepsTheUiAndSaysSo(): void
    {
        $this->start();
        $before = (string) file_get_contents("$this->project/build/dev/screens.json");
        file_put_contents("$this->project/templates/S.rk", '<VStack xmlns:k="k"><Label text="new first" /><Label text="{count}" /><k:Box /></VStack>');
        file_put_contents("$this->project/kits/k/k.slint", "export component KBox inherits Rectangle { height: 10px }}\n");
        $this->waitOut('(UI unchanged)');
        self::assertStringNotContainsString('UI reloaded', $this->out);
        self::assertSame($before, file_get_contents("$this->project/build/dev/screens.json"), 'screens.json stays in step with the UI on screen');
        file_put_contents("$this->project/kits/k/k.slint", self::KIT);
        $this->waitOut('UI reloaded');
    }

    public function testPhpEditThatChangesTheUiAlsoReloadsIt(): void
    {
        $this->start();
        file_put_contents("$this->project/src/S.php", self::screen("public string \$count = 'zero';"));
        $this->waitOut('PHP restarted');
        $this->waitOut('UI reloaded');
        usleep(500_000);
        $this->drain();
        self::assertStringNotContainsString('has no property', $this->err);
    }

    public function testModuleEventsAndRestart(): void
    {
        if (!\RageKit\Tests\Support\Fetched::module('audio')) {
            self::markTestSkipped('needs miniaudio (fetched from the audio module\'s fetch:)');
        }
        file_put_contents("$this->project/project.yml", "name: devtest\nsources:\n  - main.php\n  - src\nragekit:\n  backend: slint\n  modules: [audio]\n  assets: [assets/]\n");
        @mkdir("$this->project/assets");
        \RageKit\Tests\Modules\AudioLibraryTest::wav("$this->project/assets/short.wav", 0.5);
        file_put_contents("$this->project/main.php", self::main(true));
        $screen = static fn(string $extra) => "<?php\nnamespace Demo;\nfinal class S extends \\RageKit\\Screen {\n    public int \$count = 0;\n    public string \$status = 'Idle';\n"
            . "    private ?\\RageKit\\Audio\\Sound \$sound = null;\n"
            . "    public static function template(): string { return 'templates/S.rk'; }\n"
            . "    public function start(): void { \$this->sound = \\RageKit\\Audio\\Sound::load('assets/short.wav'); \$this->sound->onFinished(function (): void { \$this->status = 'Finished'; }); \$this->sound->play(); }\n"
            . "    $extra\n}\n";
        file_put_contents("$this->project/src/S.php", $screen(''));
        $this->start(['RAGEKIT_AUDIO_NULL' => '1'], ['-v']);
        $this->waitFile("$this->project/build/dev/state.json", '"status": "Finished"');
        file_put_contents("$this->project/src/S.php", $screen('// edited'));
        $this->waitOut('PHP restarted');
        $this->waitErr('php ready', 2);
        $err = $this->err;
        $restart = strpos($err, 'php ready');
        self::assertNotFalse(strpos($err, 'reset audio', (int) $restart), "the module is reset before the new PHP starts:\n$err");
        self::assertLessThan(strrpos($err, 'php ready'), strpos($err, 'reset audio', (int) $restart));
    }

    public function testModuleCodeChangeIsLoaded(): void
    {
        file_put_contents("$this->project/project.yml", "name: devtest\nsources:\n  - main.php\n  - src\nragekit:\n  backend: slint\n  modules: [echo]\n");
        @mkdir("$this->project/modules/echo/native", 0777, true);
        file_put_contents("$this->project/modules/echo/module.yml", "name: echo\nlibrary:\n  sources: [native]\nfunctions:\n  echo_version: { returns: string }\n");
        $native = fn(string $version) => "#include \"rk_module_echo.h\"\nRKM_API const char *rkm_echo_version(void) { return \"$version\"; }\nRKM_API void rkm_echo_init(rk_post_fn) { }\nRKM_API void rkm_echo_reset(void) { }\n";
        file_put_contents("$this->project/modules/echo/native/echo.cc", $native('v1'));
        file_put_contents("$this->project/main.php", self::main(true));
        file_put_contents("$this->project/src/S.php", "<?php\nnamespace Demo;\nfinal class S extends \\RageKit\\Screen {\n    public int \$count = 0;\n    public string \$status = '';\n"
            . "    public static function template(): string { return 'templates/S.rk'; }\n"
            . "    public function start(): void { \$this->status = \\echo_version(); }\n}\n");
        $this->start();
        $this->waitFile("$this->project/build/dev/state.json", '"status": "v1"');
        file_put_contents("$this->project/modules/echo/native/echo.cc", $native('v2'));
        $this->waitOut('echo module rebuilt', 60.0);
        $this->waitFile("$this->project/build/dev/state.json", '"status": "v2"');
    }

    private function waitFile(string $file, string $text, float $timeout = 30.0): void
    {
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            $this->drain();
            clearstatcache();
            if (str_contains((string) @file_get_contents($file), $text)) {
                self::assertTrue(true);
                return;
            }
            usleep(100_000);
        }
        self::fail("waited for '$text' in $file:\n" . @file_get_contents($file) . "\nstdout:\n$this->out\nstderr:\n$this->err");
    }

    private function waitErr(string $text, int $count, float $timeout = 20.0): void
    {
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            $this->drain();
            if (substr_count($this->err, $text) >= $count) {
                self::assertTrue(true);
                return;
            }
            usleep(100_000);
        }
        self::fail("waited for '$text' x$count\nstdout:\n$this->out\nstderr:\n$this->err");
    }

    /** @param array<string, string> $env */
    private function start(array $env = [], array $options = []): void
    {
        // Output goes to files: Windows can't read proc_open pipes without blocking.
        @unlink("$this->project/dev.out");
        @unlink("$this->project/dev.err");
        $null = Platform::isWindows() ? 'NUL' : '/dev/null';
        $this->process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/ragekit', 'dev', '--headless', ...$options], [0 => ['file', $null, 'r'], 1 => ['file', "$this->project/dev.out", 'w'], 2 => ['file', "$this->project/dev.err", 'w']], $this->pipes, $this->project, $env + ['SLINT_HOME' => InstalledToolchain::slintHome()] + getenv()); // the fixture has no .ragekit/ of its own
        $this->waitOut('app started', 120.0);
        usleep(800_000); // let PHP open its window and send `run`
    }

    private function drain(): void
    {
        clearstatcache();
        $this->out = (string) @file_get_contents("$this->project/dev.out");
        $this->err = (string) @file_get_contents("$this->project/dev.err");
    }

    private function waitOut(string $text, float $timeout = 20.0): void
    {
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            $this->drain();
            if (str_contains($this->out, $text)) {
                self::assertTrue(true);
                return;
            }
            usleep(100_000);
        }
        self::fail("waited for '$text'\nstdout:\n$this->out\nstderr:\n$this->err");
    }

    /** main.php: an App whose first screen is Demo\S (with $start: start() calls the screen's start()). */
    private static function main(bool $start): string
    {
        return "<?php\nfinal class DevTestApp extends \\RageKit\\App {\n"
            . "    private ?Demo\\S \$s = null;\n"
            . "    protected function firstScreen(): \\RageKit\\Screen { return \$this->s = new Demo\\S(); }\n"
            . ($start ? "    protected function start(): void { \$this->s->start(); }\n" : '')
            . "    protected function title(): string { return 'Dev test'; }\n"
            . "    protected function width(): int { return 300; }\n"
            . "    protected function height(): int { return 200; }\n"
            . "}\nfunction main(): void { (new DevTestApp())->run(); }\n";
    }
}
