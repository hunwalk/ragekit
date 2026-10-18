<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\ConfigMerger;
use RageKit\Cli\RageKitException;

final class ConfigMergerTest extends TestCase
{
    public function testOsThenOsArchEntriesAreMergedIn(): void
    {
        $raw = [
            'libs' => ['a'],
            'url' => 'generic',
            'platforms' => [
                'linux' => ['libs' => ['b'], 'url' => 'linux'],
                'linux-arm64' => ['libs' => ['c'], 'url' => 'linux-arm64'],
                'windows' => ['libs' => ['w']],
            ],
        ];
        self::assertSame(['libs' => ['a', 'b', 'c'], 'url' => 'linux-arm64'], ConfigMerger::forPlatform($raw, 'x.yml', 'linux-arm64'));
        self::assertSame(['libs' => ['a', 'b'], 'url' => 'linux'], ConfigMerger::forPlatform($raw, 'x.yml', 'linux-x86_64'));
        self::assertSame(['libs' => ['a', 'w'], 'url' => 'generic'], ConfigMerger::forPlatform($raw, 'x.yml', 'windows-x86_64'));
        self::assertSame(['libs' => ['a'], 'url' => 'generic'], ConfigMerger::forPlatform($raw, 'x.yml', 'macos-arm64'));
    }

    public function testUnknownPlatformKeyFails(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("x.yml: unknown platform 'linux_x86_64'");
        ConfigMerger::forPlatform(['platforms' => ['linux_x86_64' => []]], 'x.yml', 'linux-x86_64');
    }
}
