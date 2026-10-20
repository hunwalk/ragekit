<?php

declare(strict_types=1);

namespace RageKit\Cli\Dev;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Steps\FetchDependencies;
use RageKit\Cli\Build\Steps\GenerateViews;
use RageKit\Cli\Fetch\Fetcher;
use RageKit\Cli\Project\ConfigMerger;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Templates\TemplateException;

/**
 * `ragekit dev`: generates the dev shell's input, starts the shell (which runs the app's PHP),
 * then watches the project and, per change:
 *  - template, kit, image → reload the UI in place;
 *  - PHP                  → restart PHP (state restored), and reload the UI if it changed too;
 *  - a module's C++       → rebuild that module, reload it, restart PHP;
 *  - the dev shell's C++  → rebuild the shell and restart everything.
 */
final class DevSession
{
    private const POLL_US = 250_000;

    private string $devDir;
    private string $shellPath = '';
    private ShellProcess $shell;
    private DevModules $modules;

    /** @var array<string, string> screen => entry .slint file */
    private array $entries = [];

    /** @var list<string> templates, kit files and images (see DevViews) */
    private array $templateFiles = [];

    /** screens.json matching the UI the shell shows (put back when a reload fails) */
    private string $goodScreens = '';

    /** whether what the shell loads changed in the last generate() */
    private bool $uiChanged = false;

    /** @var array<string, true> hints already shown this session */
    private array $warned = [];

    /** a reload failed: the shell's UI is behind the generated files until one succeeds */
    private bool $uiStale = false;

    public function __construct(private readonly BuildContext $context, private readonly bool $onTop, private readonly bool $headless = false)
    {
        $this->devDir = $context->project->dir . '/build/dev';
        $this->modules = new DevModules($context, $this->devDir);
    }

    public function run(): int
    {
        $output = $this->context->output;
        $built = ShellBuilder::ensure($this->context, $this->devDir);
        $this->shellPath = $built['path'];
        $output->writeln('  dev shell ' . ($built['built'] ? 'built' : 'up to date') . ' (' . $this->context->relative($this->shellPath) . ')');
        foreach (FetchDependencies::dependencies($this->context) as [$owner, $dependency]) {
            if (Fetcher::ensure($dependency)) {
                $output->writeln("  fetched $owner: {$dependency->describe()}");
            }
        }
        if (!$this->generate()) {
            throw new RageKitException('fix the template error above, then run ragekit dev again');
        }
        $this->modules->prepare();
        $this->writeBootstrap();
        @unlink("$this->devDir/state.json"); // a restart before the first window must not restore an old session
        $this->startShell(restore: false);
        $output->writeln('  app started (php ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ')');

        $watcher = new Watcher($this->watchList());
        for (;;) {
            $exitCode = $this->shell->exitCode();
            if ($exitCode !== null) {
                return $exitCode;
            }
            usleep(self::POLL_US);
            $changes = $watcher->poll();
            if ($changes !== []) {
                $this->handle($changes);
                $watcher->setFiles($this->watchList());
            }
        }
    }

    /** @param array<string, list<string>> $changes Watcher kind => changed files */
    private function handle(array $changes): void
    {
        $start = hrtime(true);
        $files = implode(', ', array_map(fn(string $f) => $this->context->relative($f), array_merge(...array_values($changes))));
        foreach (array_keys($changes) as $kind) {
            if (str_starts_with($kind, 'module:')) {
                $this->moduleChanged(substr($kind, 7), $files, $start);
                unset($changes[$kind]);
            }
        }
        if (isset($changes['native'])) {
            $this->shellChanged($files, $start);
        } elseif (isset($changes['php'])) {
            $this->phpChanged($files, $start);
        } elseif (isset($changes['template'])) {
            $this->templatesChanged($files, $start);
        }
    }

    /** A module's C++ changed: rebuild it, have the shell load it again, restart PHP. */
    private function moduleChanged(string $name, string $files, int|float $start): void
    {
        try {
            $library = $this->modules->build($name);
        } catch (RageKitException $e) {
            $this->context->output->writeln('<error>ragekit: ' . $e->getMessage() . '</error> (the app keeps the old module)');
            return;
        }
        // Reloading frees everything the module held (e.g. sounds), so PHP restarts too: it
        // mustn't keep handles the new library has never seen.
        $this->shell->send("reload-module $name $library");
        $this->shell->send('restart-php');
        $this->say($files, "$name module rebuilt, PHP restarted, state restored", $start);
    }

    private function shellChanged(string $files, int|float $start): void
    {
        if ($this->restartShell(rebuild: true)) {
            $this->say($files, 'dev shell rebuilt, app restarted, state restored', $start);
        }
    }

    private function phpChanged(string $files, int|float $start): void
    {
        $screens = array_keys($this->entries);
        $ok = $this->generate();
        $this->writeBootstrap();
        if ($ok && array_keys($this->entries) !== $screens) {
            $this->restartShell(rebuild: false);
            $this->say($files, 'app restarted (screens changed), state restored', $start);
            return;
        }
        $this->shell->send('restart-php');
        // Property types and constants live in PHP: the generated UI can change too.
        $reload = $ok && ($this->uiChanged || $this->uiStale) ? $this->reload() : null;
        $this->say($files, ($ok ? 'PHP restarted, state restored' : 'PHP restarted (fix the error above)')
            . ($reload === null ? '' : ($reload ? ', UI reloaded' : ', UI unchanged (see the error above)')), $start);
    }

    private function templatesChanged(string $files, int|float $start): void
    {
        $screens = array_keys($this->entries);
        if (!$this->generate()) {
            return;
        }
        if (array_keys($this->entries) !== $screens) {
            $this->restartShell(rebuild: false);
            $this->say($files, 'app restarted (screens changed), state restored', $start);
        } elseif ($this->reload()) {
            $this->say($files, 'UI reloaded', $start);
        } else {
            $this->say($files, 'the dev shell rejected the new UI (see above) (UI unchanged)', $start);
        }
    }

    /** Regenerates app.slint, the entries and screens.json; prints a template error and returns false. */
    private function generate(): bool
    {
        try {
            $views = DevViews::write(GenerateViews::viewProject($this->context, $this->devDir), $this->devDir);
        } catch (TemplateException | RageKitException $e) {
            $this->context->output->writeln('<error>ragekit: ' . $e->getMessage() . '</error> (UI unchanged)');
            return false;
        }
        $this->entries = $views->entries;
        $this->templateFiles = $views->watch;
        foreach ($views->warnings as $warning) {
            if (!isset($this->warned[$warning])) {
                $this->warned[$warning] = true;
                $this->context->output->writeln("<comment>ragekit: $warning</comment>");
            }
        }
        $this->uiChanged = $views->changed;
        if ($this->goodScreens === '') {
            $this->goodScreens = (string) file_get_contents($views->screensJson);
        }
        return true;
    }

    /**
     * Asks the shell to load the regenerated UI and waits for the outcome. On failure the old UI
     * stays, so screens.json goes back to the version that matches it.
     */
    private function reload(): bool
    {
        $this->shell->send('reload');
        $screens = "$this->devDir/screens.json";
        if ($this->shell->readStatus(30.0) === 'reloaded') {
            $this->goodScreens = (string) file_get_contents($screens);
            $this->uiStale = false;
            return true;
        }
        file_put_contents($screens, $this->goodScreens);
        $this->uiStale = true;
        return false;
    }

    private function writeBootstrap(): void
    {
        $files = $this->phpFiles();
        $classes = Bootstrap::classes($files);
        $eager = array_values(array_filter($files, fn(string $file) => !in_array($file, $classes, true)));
        Bootstrap::write("$this->devDir/bootstrap.php", $files, $eager);
    }

    /** @return list<string> the backend's core PHP, the dev runtime, the modules' PHP and the app's */
    private function phpFiles(): array
    {
        $backend = $this->context->backend;
        $project = $this->context->project;
        $core = ConfigMerger::resolvePaths(['sources' => $backend->config['sources'] ?? []], $backend->rageKitRoot)['sources'];
        $runtime = $backend->path((string) ($backend->dev['runtime'] ?? ''));
        $app = ConfigMerger::resolvePaths(['sources' => $project->config['sources'] ?? []], $project->dir)['sources'];
        return array_values(array_unique([
            ...SourceFiles::php($core),
            ...SourceFiles::php([$runtime]),
            ...$this->modules->phpFiles(),
            ...SourceFiles::php($app),
        ]));
    }

    /** @return array<string, string> file => Watcher kind */
    private function watchList(): array
    {
        $list = array_fill_keys($this->phpFiles(), 'php') + array_fill_keys($this->templateFiles, 'template');
        foreach ($this->context->backend->dev['shell']['sources'] ?? [] as $dir) {
            foreach (glob($this->context->backend->path($dir) . '/*') ?: [] as $file) {
                $list[$file] = 'native';
            }
        }
        return $this->modules->watchList() + $list;
    }

    private function startShell(bool $restore): void
    {
        $command = [$this->shellPath];
        foreach ($this->entries as $name => $entry) {
            array_push($command, '--screen', "$name=$entry");
        }
        array_push($command, '--renderer', (string) ($this->context->project->options['renderer'] ?? 'software'));
        if ($this->onTop) {
            $command[] = '--on-top';
        }
        if ($restore) {
            $command[] = '--restore';
        }
        array_push($command, ...$this->modules->shellArguments());
        if ($this->headless) {
            $command[] = '--headless';
        }
        array_push($command, '--status-fd', '3');
        if ($this->context->output->isVerbose()) {
            array_push($command, '--log', '/dev/stderr');
        }
        array_push($command, '--', PHP_BINARY, "$this->devDir/bootstrap.php");
        $env = getenv();
        $env['RAGEKIT_DEV_SCREENS'] = "$this->devDir/screens.json";
        $env['RAGEKIT_DEV_STATE'] = "$this->devDir/state.json";
        $env['RAGEKIT_APP_DIR'] = $this->context->project->dir; // assets are read in place
        $this->shell = ShellProcess::start($command, $env, $this->context->project->dir);
    }

    /**
     * Stops the shell and starts it again (new screens, or a rebuilt shell); state comes back from
     * the snapshot. A rebuild happens first: if it fails, the running shell stays.
     */
    private function restartShell(bool $rebuild): bool
    {
        if ($rebuild) {
            try {
                $built = ShellBuilder::ensure($this->context, $this->devDir);
            } catch (RageKitException $e) {
                $this->context->output->writeln('<error>ragekit: ' . $e->getMessage() . '</error> (the app keeps running)');
                return false;
            }
            $this->shellPath = $built['path'];
            if (!$built['built']) {
                return false; // nothing that goes into the shell changed
            }
        }
        $this->shell->stop();
        $this->startShell(restore: true);
        $this->uiStale = false;
        $this->goodScreens = (string) file_get_contents("$this->devDir/screens.json");
        return true;
    }

    private function say(string $files, string $what, int|float $start): void
    {
        $ms = (int) round((hrtime(true) - $start) / 1e6);
        $this->context->output->writeln('  ' . date('H:i:s') . " $files → $what ($ms ms)");
    }
}
