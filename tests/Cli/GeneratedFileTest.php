<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\GeneratedFile;
use RageKit\Cli\Slint\SlintViewGenerator;

final class GeneratedFileTest extends TestCase
{
    public function testWritesOnlyWhenContentDiffers(): void
    {
        $file = sys_get_temp_dir() . '/rk-gen-' . uniqid();
        try {
            self::assertTrue(GeneratedFile::write($file, 'a'));
            touch($file, 1000);
            clearstatcache();
            self::assertFalse(GeneratedFile::write($file, 'a'));
            clearstatcache();
            self::assertSame(1000, filemtime($file));
            self::assertTrue(GeneratedFile::write($file, 'b'));
            self::assertSame('b', file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testEmbeddedImagesCountAsSingleFiles(): void
    {
        $dir = sys_get_temp_dir() . '/rk-img-' . uniqid();
        mkdir("$dir/project/deep", 0777, true);
        $header = "$dir/app.h";
        $slint = "$dir/app.slint";
        $image = "$dir/project/logo.svg";
        touch($slint, 1000);
        touch($image, 1000);
        touch("$dir/project/deep/unrelated.slint", 3000); // must not be scanned: images aren't kit folders
        touch($header, 2000);
        try {
            self::assertFalse(SlintViewGenerator::needsCompile($header, $slint, [], [$image]), 'image older than header');
            touch($image, 3000);
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, [], [$image]), 'edited image');
        } finally {
            foreach ([$header, $slint, $image, "$dir/project/deep/unrelated.slint"] as $f) {
                @unlink($f);
            }
            @rmdir("$dir/project/deep");
            @rmdir("$dir/project");
            @rmdir($dir);
        }
    }

    public function testSlintCompilerRunsOnlyWhenNeeded(): void
    {
        $dir = sys_get_temp_dir() . '/rk-slint-' . uniqid();
        mkdir("$dir/kit", 0777, true);
        $header = "$dir/app.h";
        $slint = "$dir/app.slint";
        $kit = "$dir/kit/entry.slint";
        $other = "$dir/kit/other.slint";
        foreach ([$slint, $kit, $other] as $f) {
            touch($f, 1000);
        }
        try {
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, [$kit]), 'header missing');
            touch($header, 2000);
            self::assertFalse(SlintViewGenerator::needsCompile($header, $slint, [$kit]), 'header newest');
            touch($slint, 3000);
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, [$kit]), 'header older than app.slint');
            touch($slint, 1000);
            touch($kit, 3000);
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, [$kit]), 'kit file touched');
            touch($kit, 1000);
            touch($other, 3000);
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, [$kit]), 'sibling in kit folder touched');
            touch($other, 1000);
            self::assertFalse(SlintViewGenerator::needsCompile($header, $slint, [$kit]));
            mkdir("$dir/kit/sub");
            touch("$dir/kit/sub/deep.slint", 3000);
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, [$kit]), 'nested file touched');
            self::assertTrue(SlintViewGenerator::needsCompile($header, $slint, ["$dir/kit"]), 'library directory');
        } finally {
            @unlink("$dir/kit/sub/deep.slint");
            @rmdir("$dir/kit/sub");
            array_map('unlink', glob("$dir/kit/*") ?: []);
            array_map('unlink', glob("$dir/*.*") ?: []);
            @rmdir("$dir/kit");
            @rmdir($dir);
        }
    }
}
