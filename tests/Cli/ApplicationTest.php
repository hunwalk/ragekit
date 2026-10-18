<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Application;

final class ApplicationTest extends TestCase
{
    public function testReportsTheInstalledVersion(): void
    {
        $app = new Application(__DIR__ . '/../..');
        // A checkout is the root package: dev-<branch>, or the tag when one is checked out.
        self::assertSame(\Composer\InstalledVersions::getPrettyVersion('ragekit/ragekit'), $app->getVersion());
        self::assertNotSame('', $app->getVersion());
    }
}
