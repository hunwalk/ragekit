<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\BackendModule;
use RageKit\Tests\Support\InstalledToolchain;

final class BackendModuleTest extends TestCase
{
    public function testSlintDeclaresViewsAndBridgeLibrary(): void
    {
        $module = BackendModule::find((string) realpath(__DIR__ . '/../..'), 'slint', InstalledToolchain::dir());

        self::assertSame('RageKit\Cli\Slint\SlintViewGenerator', $module->views['generator']);
        self::assertStringEndsWith('/bin/slint-compiler' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), $module->views['slint-compiler']);
        self::assertSame('ragekit_slint', $module->libraries[0]['name']);
        self::assertSame('c++20', $module->libraries[0]['std']);
        self::assertContains('@generated/views_glue.cc', $module->libraries[0]['sources']);
        self::assertSame('c++17', $module->config['cxx-std']);
        self::assertNotContains('slint_cpp', $module->config['link-libs'] ?? []);
    }
}
