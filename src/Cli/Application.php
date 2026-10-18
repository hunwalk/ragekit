<?php

declare(strict_types=1);

namespace RageKit\Cli;

use RageKit\Cli\Command\BuildCommand;
use RageKit\Cli\Command\DevCommand;
use RageKit\Cli\Command\DoctorCommand;
use RageKit\Cli\Command\SetupCommand;
use RageKit\Cli\Project\Paths;
use Symfony\Component\Console\Application as ConsoleApplication;

/** The `ragekit` CLI. Runs on the developer's PHP (>= 8.2), never compiled into apps. */
final class Application extends ConsoleApplication
{
    public function __construct(string $rageKitRoot)
    {
        parent::__construct('ragekit', self::version());
        $rageKitRoot = Paths::normalize($rageKitRoot);
        $this->addCommands([
            new BuildCommand($rageKitRoot, alwaysRun: false),
            new BuildCommand($rageKitRoot, alwaysRun: true),
            new DevCommand($rageKitRoot),
            new SetupCommand($rageKitRoot),
            new DoctorCommand($rageKitRoot),
        ]);
        $this->setDefaultCommand('build');
    }

    /** The installed ragekit/ragekit version as Composer knows it, e.g. 0.1.0 or dev-master. */
    private static function version(): string
    {
        if (!class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled('ragekit/ragekit')) {
            return 'unknown';
        }
        return (string) \Composer\InstalledVersions::getPrettyVersion('ragekit/ragekit');
    }
}
