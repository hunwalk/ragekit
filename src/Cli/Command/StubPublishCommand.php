<?php

declare(strict_types=1);

namespace RageKit\Cli\Command;

use RageKit\Cli\Make\AppPaths;
use RageKit\Cli\Make\Maker;
use RageKit\Cli\RageKitException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `ragekit stub:publish`: copies make:'s stubs into the app's stubs/, where make: then reads them. */
final class StubPublishCommand extends Command
{
    public function __construct(private readonly string $rageKitRoot)
    {
        parent::__construct('stub:publish');
    }

    protected function configure(): void
    {
        $this->setDescription("Copy the make: stubs into the app's stubs/ folder to change what make: writes")
            ->addOption('force', null, InputOption::VALUE_NONE, 'overwrite stubs already in stubs/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $app = AppPaths::find((string) getcwd());
            $dir = "{$app->projectDir}/stubs";
            if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
                throw new RageKitException('could not create stubs/');
            }
            $copied = [];
            foreach (Maker::stubNames() as $name) {
                if (is_file("$dir/$name") && !$input->getOption('force')) {
                    $output->writeln("  skipped stubs/$name (exists; --force overwrites)");
                    continue;
                }
                if (!copy(Maker::builtInStub($name), "$dir/$name")) {
                    throw new RageKitException("could not write stubs/$name");
                }
                $copied[] = $name;
            }
        } catch (RageKitException $e) {
            $output->writeln('<error>ragekit: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('ragekit: published ' . count($copied) . ' stub(s) to stubs/; make: uses them from now on');
        return self::SUCCESS;
    }
}
