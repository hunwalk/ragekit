<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Modules;
use RageKit\ModuleUnavailable;
use RageKit\Tests\Slint\Runtime\FakeHost;

require_once __DIR__ . '/../Slint/Runtime/FakeHost.php';

final class ModulesTest extends TestCase
{
    protected function setUp(): void
    {
        FakeHost::reset();
    }

    public function testAvailable(): void
    {
        FakeHost::$modules = ['audio' => '', 'gps' => 'not found: /x/librk_module_gps.so'];
        self::assertTrue(Modules::available('audio'));
        self::assertFalse(Modules::available('gps'));
        self::assertFalse(Modules::available('nope'));
        Modules::require('audio');
    }

    public function testRequireThrowsWithTheReason(): void
    {
        FakeHost::$modules = ['audio' => 'not found: /x/librk_module_audio.so'];
        $this->expectException(ModuleUnavailable::class);
        $this->expectExceptionMessage('audio module not available: not found: /x/librk_module_audio.so');
        Modules::require('audio');
    }
}
