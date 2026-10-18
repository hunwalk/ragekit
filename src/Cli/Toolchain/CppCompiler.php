<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** Builds command lines for the platform's C++ compiler (Gcc, Msvc). */
interface CppCompiler
{
    public function sharedLibrary(CppBuild $build): CompileCommand;

    /** A program linked with rpaths to its link paths, so it runs from the build folder. */
    public function program(CppBuild $build): CompileCommand;
}
