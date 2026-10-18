<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** g++ on Linux (and macOS, untested). */
final class Gcc implements CppCompiler
{
    public function sharedLibrary(CppBuild $build): CompileCommand
    {
        $output = "{$build->dir}/lib{$build->name}.so";
        $command = [
            'g++', "-std={$build->std}", '-O2', '-shared', '-fPIC', '-fvisibility=hidden',
            ...self::defines($build), ...$build->sources, ...self::paths($build),
            // Run without a shell, so $ORIGIN reaches the linker literally.
            '-Wl,-rpath,$ORIGIN', "-Wl,-soname,lib{$build->name}.so", '-o', $output,
        ];
        return new CompileCommand($command, $output, $output);
    }

    public function program(CppBuild $build): CompileCommand
    {
        $output = "{$build->dir}/{$build->name}";
        $rpaths = array_map(fn(string $path) => "-Wl,-rpath,$path", $build->linkPaths);
        $command = [
            'g++', "-std={$build->std}", '-O1', ...self::defines($build), ...$build->sources, ...self::paths($build),
            '-lpthread', ...$rpaths, '-o', $output,
        ];
        return new CompileCommand($command, $output, $output);
    }

    /** @return list<string> */
    private static function defines(CppBuild $build): array
    {
        return array_map(fn(string $define) => "-D$define", $build->defines);
    }

    /** @return list<string> include paths, link paths, libraries */
    private static function paths(CppBuild $build): array
    {
        return [
            ...array_map(fn(string $path) => "-I$path", $build->includePaths),
            ...array_map(fn(string $path) => "-L$path", $build->linkPaths),
            ...array_map(fn(string $lib) => "-l$lib", $build->linkLibs),
        ];
    }
}
