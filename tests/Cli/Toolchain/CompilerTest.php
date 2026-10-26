<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Toolchain\CppBuild;
use RageKit\Cli\Toolchain\Gcc;
use RageKit\Cli\Toolchain\Msvc;

/** Command lines only: both compilers are checked on every OS. */
final class CompilerTest extends TestCase
{
    private static function build(array $defines = []): CppBuild
    {
        return new CppBuild('ragekit_slint', '/b', ['/s/a.cc', '/g/glue.cc'], ['/s', '/g'], ['/slint/lib'], ['slint_cpp'], 'c++20', $defines);
    }

    public function testGccSharedLibrary(): void
    {
        $lib = (new Gcc())->sharedLibrary(self::build());
        self::assertSame('/b/libragekit_slint.so', $lib->output);
        self::assertSame('/b/libragekit_slint.so', $lib->link);
        self::assertSame([
            'g++', '-std=c++20', '-O2', '-shared', '-fPIC', '-fvisibility=hidden', '/s/a.cc', '/g/glue.cc',
            '-I/s', '-I/g', '-L/slint/lib', '-lslint_cpp',
            '-Wl,-rpath,$ORIGIN', '-Wl,-soname,libragekit_slint.so', '-o', '/b/libragekit_slint.so',
        ], $lib->command);
    }

    public function testGccProgram(): void
    {
        $program = (new Gcc())->program(new CppBuild('ragekit-devshell', '/b', ['/s/a.cc'], ['/s'], ['/slint/lib'], ['slint_cpp'], 'c++20', ['SLINT_FEATURE_EXPERIMENTAL']));
        self::assertSame('/b/ragekit-devshell', $program->output);
        self::assertSame(['g++', '-std=c++20', '-O1', '-DSLINT_FEATURE_EXPERIMENTAL', '/s/a.cc', '-I/s', '-L/slint/lib', '-lslint_cpp', '-lpthread', '-Wl,-rpath,/slint/lib', '-o', '/b/ragekit-devshell'], $program->command);
    }

    public function testMsvcSharedLibrary(): void
    {
        $lib = (new Msvc())->sharedLibrary(self::build());
        self::assertSame('/b/ragekit_slint.dll', $lib->output);
        self::assertSame('/b/ragekit_slint.lib', $lib->link);
        self::assertSame([
            'cl.exe', '/nologo', '/LD', '/MD', '/O2', '/EHsc', '/utf-8', '/bigobj', '/std:c++20', '/Zc:__cplusplus', '/DRK_BUILDING_BRIDGE', '/Fo/b/',
            '/s/a.cc', '/g/glue.cc', '/I/s', '/I/g', '/Fe/b/ragekit_slint.dll', '/link', '/LIBPATH:/slint/lib', 'slint_cpp.lib',
        ], $lib->command);
    }

    public function testMsvcProgram(): void
    {
        $program = (new Msvc())->program(new CppBuild('ragekit-devshell', '/b', ['/s/a.cc'], ['/s'], ['/slint/lib'], ['slint_cpp'], 'c++20', ['SLINT_FEATURE_EXPERIMENTAL']));
        self::assertSame('/b/ragekit-devshell.exe', $program->output);
        self::assertSame([
            'cl.exe', '/nologo', '/MD', '/O1', '/EHsc', '/utf-8', '/bigobj', '/std:c++20', '/Zc:__cplusplus', '/DSLINT_FEATURE_EXPERIMENTAL',
            '/s/a.cc', '/I/s', '/Fe/b/ragekit-devshell.exe', '/link', '/LIBPATH:/slint/lib', 'slint_cpp.lib',
        ], $program->command);
    }

    public function testMsvcUsesSlintsDllImportLibrary(): void
    {
        $dir = sys_get_temp_dir() . '/rk-msvc-' . uniqid();
        mkdir($dir);
        touch("$dir/slint_cpp.dll.lib");
        $lib = (new Msvc())->sharedLibrary(new CppBuild('x', '/b', ['/s/a.cc'], [], [$dir], ['slint_cpp', 'user32'], 'c++20'));
        self::assertSame(['slint_cpp.dll.lib', 'user32.lib'], array_slice($lib->command, -2));
    }
}
