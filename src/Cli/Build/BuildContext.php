<?php

declare(strict_types=1);

namespace RageKit\Cli\Build;

use RageKit\Cli\Project\BackendModule;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\Project\Project;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Setup\ToolchainDir;
use RageKit\Cli\Toolchain\Toolchain;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * State shared by the build steps. Everything known up front is readonly; steps only change
 * the tpc config they build up and, at the end, the exit code.
 */
final class BuildContext
{
    /** tpc settings, filled by MergeConfig and refined by the following steps */
    public array $config = [];

    /** Exit code of the command (the app's for `run`). */
    public int $exitCode = 0;

    /** @var list<array<string, mixed>> module libraries to build (backend.yml `libraries:` shape), never linked */
    public array $moduleLibraries = [];

    /** Output of tools the current step ran quietly; the pipeline prints it if the step fails. */
    public string $capturedOutput = '';

    /** Live text after the current step's progress line, see StatusLine. */
    public readonly StatusLine $status;

    /** @param string[] $tpcArgs extra arguments passed through to tpc */
    public function __construct(
        public readonly Project $project,
        public readonly BackendModule $backend,
        public readonly Toolchain $toolchain,
        public readonly OutputInterface $output,
        public readonly array $tpcArgs = [],
    ) {
        $this->status = new StatusLine($output);
    }

    /**
     * The context for building $project: its backend and the toolchain `ragekit setup` installed
     * for it (see Setup\ToolchainDir).
     *
     * @param string[] $tpcArgs
     */
    public static function forProject(string $rageKitRoot, Project $project, OutputInterface $output, array $tpcArgs = []): self
    {
        $pins = Pins::forRageKit($rageKitRoot);
        $toolchainDir = ToolchainDir::find($project->dir, $pins);
        ToolchainDir::markUsed($toolchainDir);
        return new self(
            $project,
            BackendModule::find($rageKitRoot, $project->backend, $toolchainDir),
            Toolchain::detect($toolchainDir, $pins),
            $output,
            $tpcArgs,
        );
    }

    /**
     * Runs a build tool: streamed live with -v, otherwise captured into $capturedOutput (shown
     * only if the step then fails). In a terminal, $status ("compiling app.h") and the seconds
     * so far appear after the step's progress line meanwhile. Returns the exit code.
     */
    public function exec(array $command, string $cwd, string $status = ''): int
    {
        if ($this->output->isVerbose()) {
            return $this->toolchain->run($command, $cwd);
        }
        $tick = null;
        if ($status !== '' && $this->status->enabled()) {
            $this->status->show($status);
            $tick = fn(int $seconds) => $this->status->show($status, $seconds);
        }
        [$code, $this->capturedOutput] = $this->toolchain->capture($command, $cwd, $tick);
        return $code;
    }

    /** A path for display, relative to the app dir when inside it. */
    public function relative(string $path): string
    {
        $path = Paths::normalize($path);
        $prefix = $this->project->dir . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** `ragekit: { nano: true }`: build with TypePHP Nano (no Zend VM, no libphp). */
    public function nano(): bool
    {
        return ($this->project->options['nano'] ?? false) === true;
    }

    /** Where ragekit writes generated code (templates → Slint/C++/PHP, embedded files). */
    public function generatedDir(): string
    {
        return $this->buildDir() . '/generated';
    }

    /** One build dir per backend, so switching backends doesn't mix caches or runtime files. */
    public function buildDir(): string
    {
        return $this->project->dir . '/build/' . $this->backend->name;
    }

    public function tpcConfigFile(): string
    {
        return $this->buildDir() . '/tpc.yml';
    }

    /** tpc's output path, without extension. */
    public function outputBase(): string
    {
        return $this->buildDir() . '/' . $this->project->name;
    }
}
