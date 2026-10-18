<?php

declare(strict_types=1);

namespace RageKit\Cli\Build;

use RageKit\Cli\RageKitException;

/**
 * Runs steps in order and prints one progress line per step:
 *
 *   [10/11] Copy assets ......... 3 files (1 ms)
 *
 * Tool output (tpc, compilers) is captured and only shown when a step fails; meanwhile a
 * terminal shows what the step is running and for how long (StatusLine). With -v tool output
 * streams live, so the step label gets its own line and the summary follows below.
 */
final class Pipeline
{
    /** @param Step[] $steps */
    public function __construct(private readonly array $steps)
    {
    }

    /** The default build: from the merged config to the compiled exe. */
    public static function build(): self
    {
        return new self([
            new Steps\MergeConfig(),
            new Steps\ApplyOptions(),
            new Steps\TpcWorkarounds(),
            new Steps\FetchDependencies(),
            new Steps\GenerateViews(),
            new Steps\PrepareModules(),
            new Steps\BuildLibrary(),
            new Steps\PrepareHostPhp(),
            new Steps\CopyRuntimeFiles(),
            new Steps\CopyAssets(),
            new Steps\WriteTpcConfig(),
            new Steps\CompileWithTpc(),
        ]);
    }

    public function then(Step $step): self
    {
        return new self([...$this->steps, $step]);
    }

    public function run(BuildContext $context): void
    {
        $output = $context->output;
        $verbose = $output->isVerbose();
        $count = count($this->steps);
        $width = max(array_map(fn(Step $s) => strlen($s->name()), $this->steps)) + 4;
        $pipelineStart = hrtime(true);

        $output->writeln("ragekit: <info>{$context->project->name}</info> (backend: {$context->backend->name})");
        foreach ($this->steps as $i => $step) {
            $label = sprintf('  <fg=gray>[%d/%d]</> %s ', $i + 1, $count, $step->name());
            $leader = str_repeat('.', $width - strlen($step->name()));
            $output->write($verbose ? $label . "\n" : "$label<fg=gray>$leader</> ");
            $context->status->startsAt(strlen(sprintf('  [%d/%d] %s ', $i + 1, $count, $step->name())) + strlen($leader) + 1);

            $context->capturedOutput = '';
            $start = hrtime(true);
            try {
                $summary = $step->run($context);
            } catch (RageKitException $e) {
                $context->status->clear();
                $output->writeln(($verbose ? '        ' : '') . '<error>failed</error>');
                if ($context->capturedOutput !== '') {
                    $output->writeln(rtrim($context->capturedOutput), $output::OUTPUT_RAW);
                }
                throw $e;
            }
            $context->status->clear();
            $time = self::duration(hrtime(true) - $start);
            $output->writeln(($verbose ? '        -> ' : '') . "$summary <fg=gray>($time)</>");
        }
        $output->writeln('ragekit: done in ' . self::duration(hrtime(true) - $pipelineStart));
    }

    private static function duration(int|float $nanoseconds): string
    {
        $ms = $nanoseconds / 1e6;
        return $ms < 1000 ? sprintf('%.0f ms', $ms) : sprintf('%.1f s', $ms / 1000);
    }
}
