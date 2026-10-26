<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** cl.exe on Windows (its environment comes from MsvcEnvironment). */
final class Msvc implements CppCompiler
{
    public function sharedLibrary(CppBuild $build): CompileCommand
    {
        $output = "{$build->dir}/{$build->name}.dll";
        $command = [
            // /bigobj: the generated views_glue.cc of a larger app (Slint's code for every screen) has more
            // sections than an object file normally may (C1128).
            'cl.exe', '/nologo', '/LD', '/MD', '/O2', '/EHsc', '/utf-8', '/bigobj', "/std:{$build->std}", '/Zc:__cplusplus',
            // The bridge exports its C API when this is defined (RK_API in ragekit_slint.h).
            '/DRK_BUILDING_BRIDGE', "/Fo{$build->dir}/",
            ...self::defines($build), ...$build->sources, ...self::includes($build),
            "/Fe$output", '/link', ...self::libraries($build),
        ];
        return new CompileCommand($command, $output, "{$build->dir}/{$build->name}.lib");
    }

    public function program(CppBuild $build): CompileCommand // the dev shell
    {
        $output = "{$build->dir}/{$build->name}.exe";
        $command = [
            'cl.exe', '/nologo', '/MD', '/O1', '/EHsc', '/utf-8', '/bigobj', "/std:{$build->std}", '/Zc:__cplusplus',
            ...self::defines($build), ...$build->sources, ...self::includes($build),
            "/Fe$output", '/link', ...self::libraries($build),
        ];
        return new CompileCommand($command, $output, $output);
    }

    /** @return list<string> */
    private static function defines(CppBuild $build): array
    {
        return array_map(fn(string $define) => "/D$define", $build->defines);
    }

    /** @return list<string> */
    private static function includes(CppBuild $build): array
    {
        return array_map(fn(string $path) => "/I$path", $build->includePaths);
    }

    /**
     * Link paths and libraries (after /link). Slint ships its import library as
     * slint_cpp.dll.lib; others use name.lib, and link-libs need the full file name on Windows.
     *
     * @return list<string>
     */
    private static function libraries(CppBuild $build): array
    {
        $args = array_map(fn(string $path) => "/LIBPATH:$path", $build->linkPaths);
        foreach ($build->linkLibs as $lib) {
            $file = "$lib.lib";
            foreach ($build->linkPaths as $path) {
                if (is_file("$path/$lib.dll.lib")) {
                    $file = "$lib.dll.lib";
                    break;
                }
            }
            $args[] = $file;
        }
        return $args;
    }
}
