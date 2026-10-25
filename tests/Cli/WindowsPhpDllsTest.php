<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Build\Steps\PrepareHostPhp;

/** Normal Windows builds put the release's PHP DLLs next to the exe, fresh on every build. */
final class WindowsPhpDllsTest extends TestCase
{
    public function testCopiesPhpAndItsDependenciesOverStaleCopies(): void
    {
        $dir = sys_get_temp_dir() . '/rk-dlls-' . uniqid();
        mkdir("$dir/release", 0777, true);
        mkdir("$dir/build");
        foreach (['php8ts.dll', 'phpx.dll', 'libmpdec-4.0.1.dll', 'libmpdec++-4.0.1.dll', 'gmp-10.dll', 'mpfr-6.dll', 'libcrypto-3-x64.dll', 'tpc.exe'] as $file) {
            file_put_contents("$dir/release/$file", "new $file");
        }
        file_put_contents("$dir/build/phpx.dll", 'stale');

        $copied = PrepareHostPhp::copyWindowsPhpDlls("$dir/release", "$dir/build");

        self::assertSame(['gmp-10.dll', 'libmpdec++-4.0.1.dll', 'libmpdec-4.0.1.dll', 'mpfr-6.dll', 'php8ts.dll', 'phpx.dll'], $copied);
        self::assertSame('new phpx.dll', file_get_contents("$dir/build/phpx.dll"), 'a stale copy is replaced');
        self::assertFileDoesNotExist("$dir/build/libcrypto-3-x64.dll");
        self::assertFileDoesNotExist("$dir/build/tpc.exe");
    }
}
