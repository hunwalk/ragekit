<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Setup;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Setup\BuildTools;

/** The offer `ragekit setup` and the starter's Composer hook make on Windows without MSVC. */
final class BuildToolsTest extends TestCase
{
    /** @var list<string> */
    private array $said = [];
    private int $installs = 0;

    private function offer(?callable $ask, int $exitCode = 0): bool
    {
        return BuildTools::offer($ask, function () use ($exitCode): int {
            $this->installs++;
            return $exitCode;
        }, function (string $line): void {
            $this->said[] = $line;
        });
    }

    public function testYesInstalls(): void
    {
        $asked = null;
        self::assertTrue($this->offer(function (string $question) use (&$asked): bool {
            $asked = $question;
            return true;
        }));
        self::assertSame(1, $this->installs);
        self::assertStringContainsString('[Y/n]', (string) $asked);
    }

    public function testNoDoesNotInstallAndSaysHowToLater(): void
    {
        self::assertFalse($this->offer(fn(string $q): bool => false));
        self::assertSame(0, $this->installs);
        self::assertStringContainsString('install-build-tools.ps1', implode("\n", $this->said));
    }

    public function testNobodyToAskOnlyExplains(): void
    {
        self::assertFalse($this->offer(null));
        self::assertSame(0, $this->installs);
        self::assertStringContainsString('install-build-tools.ps1', implode("\n", $this->said));
    }

    public function testAFailedInstallSaysSo(): void
    {
        self::assertFalse($this->offer(fn(string $q): bool => true, 1602));
        self::assertStringContainsString('exit code 1602', implode("\n", $this->said));
    }

    public function testNothingIsMissingOffLinuxOrWithACompiler(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertFalse(BuildTools::missing());
        } else {
            self::markTestSkipped('depends on this machine');
        }
    }
}
