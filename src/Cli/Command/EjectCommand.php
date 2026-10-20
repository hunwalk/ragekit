<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Templates\KitEjector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `ragekit eject <kit> [name]`: copies a kit into the app to customise it, and switches the app to it. */
final class EjectCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot)
    {
        parent::__construct('eject');
    }

    protected function configure(): void
    {
        $this->setDescription("Copy a kit into the app (kits/<name>/) to change how it draws, and use it")
            ->addArgument('kit', InputArgument::OPTIONAL, 'the kit to copy (without it: list the kits)')
            ->addArgument('name', InputArgument::OPTIONAL, "the copy's name (default: the project's name)")
            ->addOption('no-use', null, InputOption::VALUE_NONE, "copy only; don't switch project.yml to it");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectDir = (string) getcwd();
        try {
            if (!is_file("$projectDir/project.yml")) {
                throw new RageKitException("run it in your app's folder (the one with project.yml)");
            }
            $ejector = new KitEjector($this->rageKitRoot);
            $kit = $input->getArgument('kit');
            if ($kit === null) {
                $output->writeln('Kits you can eject (ragekit eject <kit> [name]):');
                foreach ($ejector->kits($projectDir) as $name => $dir) {
                    $output->writeln("  $name  <comment>$dir</comment>");
                }
                return self::SUCCESS;
            }
            $result = $ejector->eject($projectDir, (string) $kit, $input->getArgument('name'), !$input->getOption('no-use'));
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln("ragekit: ejected {$result->kit} into kits/{$result->name}"
            . ($result->renamed === [] ? '' : " (components renamed {$result->fromPrefix}… → {$result->toPrefix}…: " . count($result->renamed) . ' names)'));
        $output->writeln($result->used ? "  project.yml now uses kit: {$result->name}" : "  to use it: set kit: {$result->name} under ragekit: in project.yml");
        if ($result->gitignore !== null) {
            $output->writeln("  add this to your .gitignore (the build fetches it): {$result->gitignore}");
        }
        $output->writeln("  edit kits/{$result->name}/*.slint to change how elements look; ragekit dev reloads kit edits");
        return self::SUCCESS;
    }
}
