<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Check;
use RageKit\Cli\Setup\Doctor;
use RageKit\Cli\Setup\Pins;
use RageKit\Cli\Setup\ToolchainDir;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `ragekit doctor`: checks what builds need; exit code 1 when something is missing. */
final class DoctorCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot)
    {
        parent::__construct('doctor');
    }

    protected function configure(): void
    {
        $this->setDescription('Check the toolchain (TypePHP, Slint, C++ compiler, PHP) and say how to fix what is missing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $cwd = (string) getcwd();
            $pins = Pins::forRageKit($this->rageKitRoot);
            $checks = (new Doctor($pins, ToolchainDir::find($cwd, $pins), $cwd))->checks();
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $width = max(array_map(fn(Check $check) => strlen($check->name), $checks));
        $failed = false;
        foreach ($checks as $check) {
            $mark = match ($check->status) {
                Check::OK => '<info>✓</info>',
                Check::WARN => '<comment>!</comment>',
                default => '<error>✗</error>',
            };
            $failed = $failed || $check->status === Check::FAIL;
            $output->writeln("  $mark " . str_pad($check->name, $width) . "  $check->detail");
        }
        $output->writeln($failed ? 'ragekit: something is missing (see ✗ above)' : 'ragekit: ready to build');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
