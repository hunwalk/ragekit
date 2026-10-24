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

    public function testVcvarsVariablesReplaceTheSameNameInAnyCase(): void
    {
        $env = MsvcEnvironment::merge(['Path' => 'C:\\old', 'HOME' => 'C:\\me'], "PATH=C:\\msvc;C:\\old\r\nINCLUDE=C:\\inc\r\n");
        self::assertSame(['HOME' => 'C:\\me', 'PATH' => 'C:\\msvc;C:\\old', 'INCLUDE' => 'C:\\inc'], $env);
    }
}
