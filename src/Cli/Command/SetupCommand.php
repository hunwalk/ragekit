<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\Fetch\Fetcher;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\BuildTools;
use RageKit\Cli\Setup\Installer;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Setup\ToolchainDir;
use RageKit\Cli\Toolchain\Platform;
use RageKit\Cli\Toolchain\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/** `ragekit setup`: installs the pinned toolchain (toolchain/toolchain.yml) into .ragekit/. */
final class SetupCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot)
    {
        parent::__construct('setup');
    }

    protected function configure(): void
    {
        $this->setDescription('Install the TypePHP and Slint versions this RageKit builds with (shared per user, or into a .ragekit/ folder)');
        $this->addOption('prune', null, InputOption::VALUE_NONE, 'Also delete the per-user toolchains of other RageKit versions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $pins = Pins::forRageKit($this->rageKitRoot);
            $dir = ToolchainDir::find((string) getcwd(), $pins);
            $output->writeln("ragekit setup: <info>$dir</info> (" . Platform::key() . ')');
            $missing = [];
            foreach ($pins->components() as $component) {
                foreach (Installer::tools($component) as $tool) {
                    if (Platform::onPath($tool) === null) {
                        $missing[$tool] = $tool === '7z' ? '7z (7-Zip)' : $tool;
                    }
                }
            }
            if ($missing !== []) {
                throw new RageKitException('setup needs ' . implode(', ', $missing) . ' on PATH (see ragekit doctor)');
            }
            $process = new Process(getenv());
            $say = fn(string $line) => $output->writeln("  $line");
            if (getenv(BuildTools::OFFERED) === false && BuildTools::missing()) {
                // Composer's scripts can't read the keyboard on Windows: only ask in a console.
                $canAsk = $input->isInteractive() && stream_isatty(STDIN);
                BuildTools::offer(
                    $canAsk ? fn(string $question): bool => (bool) $this->getHelper('question')->ask($input, $output, new ConfirmationQuestion("  $question", true)) : null,
                    fn(): int => BuildTools::install(),
                    $say,
                );
            }
            foreach ($pins->components() as $name => $component) {
                if (!$component->available()) {
                    $output->writeln("  <comment>$name: nothing pinned for " . Platform::key() . ' (set its *_HOME variable)</comment>');
                    continue;
                }
                if ($name === 'typephp' && PHP_VERSION_ID < 80400) {
                    throw new RageKitException('TypePHP needs PHP 8.4+ (this is ' . PHP_VERSION . '): run ragekit setup with PHP 8.4 or 8.5');
                }
                $installed = Installer::install($component, "$dir/$name", $process, $say);
                $output->writeln("  $name: " . ($installed ? '<info>installed</info>' : 'up to date'));
            }
            if ($input->getOption('prune')) {
                $this->prune($pins, $output);
            }
            $output->writeln('ragekit: toolchain ready (check everything with ragekit doctor)');
            return self::SUCCESS;
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }

    /** Deletes the per-user toolchains of other pins; apps still on those versions need `ragekit setup` again. */
    private function prune(Pins $pins, OutputInterface $output): void
    {
        $others = ToolchainDir::others($pins);
        if ($others === []) {
            $output->writeln('  prune: no other toolchains');
            return;
        }
        foreach ($others as $dir) {
            $used = ToolchainDir::lastUsed($dir);
            $size = (int) trim((string) shell_exec('du -sm ' . escapeshellarg($dir) . ' 2>/dev/null | cut -f1'));
            $details = array_filter([$size > 0 ? "$size MB" : null, $used === null ? null : 'last used ' . date('Y-m-d', $used)]);
            Fetcher::remove($dir);
            $output->writeln("  prune: deleted $dir" . ($details === [] ? '' : ' (' . implode(', ', $details) . ')'));
        }
        $output->writeln('  prune: apps on those RageKit versions need `ragekit setup` again before they build');
    }
}
