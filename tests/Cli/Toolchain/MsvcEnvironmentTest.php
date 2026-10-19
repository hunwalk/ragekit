<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Toolchain\MsvcEnvironment;

final class MsvcEnvironmentTest extends TestCase
{
    public function testInstallHintNamesTheScriptThatShipsWithRageKit(): void
    {
        $hint = MsvcEnvironment::installHint();
        self::assertMatchesRegularExpression('/-File "(.+install-build-tools\.ps1)"$/', $hint);
        preg_match('/-File "(.+)"$/', $hint, $m);
        self::assertFileExists(str_replace('\\', '/', $m[1]));
    }
}
