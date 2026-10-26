<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Pipeline;
use RageKit\Cli\Package\Packager;
use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `ragekit package [--format=tar|deb]`: build, then put the app into dist/ as a file to hand out. */
final class PackageCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot)
    {
        parent::__construct('package');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Build the app, then package it into dist/ (tar: a .tar.gz folder; deb: a Debian package)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'tar or deb', 'tar')
            ->addOption('kit', null, InputOption::VALUE_REQUIRED, 'Kit to build with (overrides project.yml)')
            ->addOption('nano', null, InputOption::VALUE_NONE, 'Build with TypePHP Nano (same as ragekit: { nano: true })');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $project = Project::load((string) getcwd(), null, $input->getOption('kit'));
            if ($input->getOption('nano')) {
                $project = $project->withOption('nano', true);
            }
            $context = BuildContext::forProject($this->rageKitRoot, $project, $output);
            Pipeline::build()->run($context);
            if ($context->exitCode !== 0) {
                return $context->exitCode;
            }
            $file = (new Packager($context))->package((string) $input->getOption('format'));
            $output->writeln('ragekit: packaged ' . substr($file, strlen($project->dir) + 1) . ' (' . number_format(filesize($file) / 1048576, 1) . ' MB)');
            return self::SUCCESS;
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
