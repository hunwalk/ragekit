<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Build\Pipeline;
use RageKit\Cli\Build\Steps\RunExecutable;
use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `ragekit build [--run]` and `ragekit run`: the build pipeline, optionally followed by the app. */
final class BuildCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot, private readonly bool $alwaysRun)
    {
        parent::__construct($alwaysRun ? 'run' : 'build');
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->alwaysRun
                ? 'Build the app in the current directory and start it'
                : 'Build the app in the current directory (project.yml) into build/<backend>/')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Backend to build for (overrides project.yml), e.g. slint')
            ->addOption('kit', null, InputOption::VALUE_REQUIRED, 'Kit to build with (overrides project.yml), e.g. shadcn or material')
            ->addOption('nano', null, InputOption::VALUE_NONE, 'Build with TypePHP Nano (same as ragekit: { nano: true })')
            ->addArgument('tpc-args', InputArgument::IS_ARRAY, 'Extra arguments for tpc, after --');
        if (!$this->alwaysRun) {
            $this->addOption('run', null, InputOption::VALUE_NONE, 'Start the app after a successful build');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $project = Project::load((string) getcwd(), $input->getOption('backend'), $input->getOption('kit'));
            if ($input->getOption('nano')) {
                $project = $project->withOption('nano', true);
            }
            $context = BuildContext::forProject($this->rageKitRoot, $project, $output, $input->getArgument('tpc-args'));

            $pipeline = Pipeline::build();
            if ($this->alwaysRun || $input->getOption('run')) {
                $pipeline = $pipeline->then(new RunExecutable());
            }
            $pipeline->run($context);
            return $context->exitCode;
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
