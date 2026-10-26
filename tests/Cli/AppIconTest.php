<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Build\AppIcon;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;

/** project.yml `ragekit: { icon: … }` (in a running app: tests/e2e/links.sh checks the window and .desktop). */
final class AppIconTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const PNG = __DIR__ . '/../e2e/images/assets/red.png';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-icon-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function project(string $icon): Project
    {
        file_put_contents("$this->dir/project.yml", "name: t\nragekit:\n  backend: slint\n" . ($icon === '' ? '' : "  icon: $icon\n"));
        return Project::load($this->dir);
    }

    public function testPath(): void
    {
        copy(self::PNG, "$this->dir/icon.png");
        self::assertSame('', AppIcon::path($this->project('')));
        self::assertSame(realpath("$this->dir/icon.png"), AppIcon::path($this->project('icon.png')));
    }

    public function testMissingOrNotAnImage(): void
    {
        file_put_contents("$this->dir/icon.gif", 'GIF');
        foreach (['nope.png' => "doesn't exist", 'icon.gif' => 'must be a .png or .svg'] as $icon => $message) {
            try {
                AppIcon::path($this->project($icon));
                self::fail("$icon was accepted");
            } catch (RageKitException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testIcoWrapsThePng(): void
    {
        $png = (string) file_get_contents(self::PNG);
        $ico = AppIcon::ico(self::PNG);
        self::assertSame([0, 1, 1], array_values(unpack('v3', substr($ico, 0, 6))), 'ICONDIR: an icon with one image');
        $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', substr($ico, 6, 16));
        self::assertSame(['width' => 64, 'height' => 64, 'colors' => 0, 'reserved' => 0, 'planes' => 1, 'bits' => 32, 'size' => strlen($png), 'offset' => 22], $entry);
        self::assertSame($png, substr($ico, 22));
    }

    public function testEveryWindowGetsTheIcon(): void
    {
        file_put_contents("$this->dir/S.rk", '<VStack><Label text="a" /></VStack>');
        $class = new ClassInfo('Demo\S', 'S', "$this->dir/S.php", true, "$this->dir/S.rk");
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $slint = (new SlintCompiler($kits, 'shadcn', icon: '/app/icon.png'))->compile(['Demo\S' => $class])->slint;
        self::assertStringContainsString('icon: @image-url("/app/icon.png");', $slint);
        $none = (new SlintCompiler($kits, 'shadcn'))->compile(['Demo\S' => $class])->slint;
        self::assertStringNotContainsString('icon: @image-url', $none);
    }
}
