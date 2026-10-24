<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\Make\AppPaths;
use RageKit\Cli\Make\MakeKind;
use RageKit\Cli\Make\Maker;
use RageKit\Cli\RageKitException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/** `ragekit make:<kind> <Name>`: one command class for every kind (screen, window, store, …). */
final class MakeCommand extends Command
{
    private const DESCRIPTIONS = [
        'screen' => 'Create a screen (src/Screens) and its template',
        'window' => 'Create a window with its own title bar (src/Windows) and its template',
        'component' => 'Create a child component (src/Components) and its template',
        'store' => 'Create a store (src/Stores) and add it to the app\'s root store',
        'service' => 'Create a service for outside calls, e.g. an API client (src/Services)',
        'flow' => 'Create a flow: one user action step by step (src/Flows)',
    ];

    public function __construct(private readonly string $rageKitRoot, private readonly MakeKind $kind)
    {
        parent::__construct("make:{$kind->value}");
    }

    protected function configure(): void
    {
        $example = $this->kind->suffix() === '' ? 'Session' : 'Login';
        $this->setDescription(self::DESCRIPTIONS[$this->kind->value])
            ->addArgument('name', InputArgument::OPTIONAL, "the class name, e.g. $example or Auth/$example (sub-folder)")
            ->addOption('force', null, InputOption::VALUE_NONE, 'overwrite files that already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $app = AppPaths::find((string) getcwd());
            $name = (string) $input->getArgument('name');
            if ($name === '' && $input->isInteractive()) {
                $name = (string) (new QuestionHelper())->ask($input, $output, new Question("Name of the {$this->kind->value}: "));
            }
            if (trim($name) === '') {
                throw new RageKitException("give a name: ragekit make:{$this->kind->value} <Name>");
            }
            $made = (new Maker($this->rageKitRoot, $app))->make($this->kind, $name, (bool) $input->getOption('force'));
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('ragekit: created ' . implode(', ', $made->created) . ($made->changed === [] ? '' : '; changed ' . implode(', ', $made->changed)));
        if ($app->namespaceNote !== null) {
            $output->writeln("  <comment>{$app->namespaceNote}</comment>");
        }
        foreach ($made->hints as $hint) {
            $output->writeln("  $hint");
        }
        return self::SUCCESS;
    }
}
