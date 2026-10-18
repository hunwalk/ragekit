<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\Build\BuildContext;
use RageKit\Cli\Dev\DevSession;
use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `ragekit dev`: run the app in the dev shell with hot reload. */
final class DevCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot)
    {
        parent::__construct('dev');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run the app with hot reload: template edits reload the UI, PHP edits restart PHP with state kept')
            ->addOption('kit', null, InputOption::VALUE_REQUIRED, 'Kit to use (overrides project.yml), e.g. shadcn or material')
            ->addOption('on-top', null, InputOption::VALUE_NONE, 'Keep the app\'s windows above other windows (also ragekit: dev: on-top: true)')
            ->addOption('headless', null, InputOption::VALUE_NONE, 'No windows (Slint\'s testing backend); for tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $project = Project::load((string) getcwd(), null, $input->getOption('kit'));
            $context = BuildContext::forProject($this->rageKitRoot, $project, $output);
            if ($context->backend->dev === []) {
                throw new RageKitException("dev mode needs the slint backend (this app uses {$context->backend->name})");
            }
            $onTop = $input->getOption('on-top') || (($project->options['dev']['on-top'] ?? false) === true);
            $kit = (string) ($project->options['kit'] ?? 'material');
            $output->writeln("ragekit dev: <info>{$project->name}</info> (kit $kit" . ($onTop ? ', on top' : '') . ') · watching templates, kits, assets, PHP');
            $output->writeln('  <fg=gray>dev mode runs full PHP — run ragekit build to check that TypePHP compiles it</>');
            $session = new DevSession($context, $onTop, (bool) $input->getOption('headless'));
            return $session->run();
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
