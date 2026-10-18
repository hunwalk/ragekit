<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Dev\Bootstrap;

final class BootstrapTest extends TestCase
{
    public function testBootstrapAutoloadsClassesAndRunsMain(): void
    {
        $dir = sys_get_temp_dir() . '/rk-boot-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/B.php", "<?php\nnamespace X;\nclass B extends A { public function hi(): string { return 'hi ' . B::class; } }\n");
        file_put_contents("$dir/A.php", "<?php\nnamespace X;\nabstract class A {}\ninterface I {}\nenum E { case One; }\n");
        file_put_contents("$dir/main.php", "<?php\nfunction main(): void { \$x = new class {}; echo (new X\\B())->hi(), ' ', X\\E::One->name; }\n");
        Bootstrap::write("$dir/boot.php", ["$dir/A.php", "$dir/B.php", "$dir/main.php"], ["$dir/main.php"]);
        self::assertSame('hi X\\B One', shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/boot.php")));
    }

    public function testFindsDeclaredClasses(): void
    {
        $dir = sys_get_temp_dir() . '/rk-boot-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/A.php", "<?php\nnamespace X\\Y;\nfinal class A { public function f() { return static::class; } }\ntrait T {}\n");
        self::assertSame(['X\\Y\\A' => "$dir/A.php", 'X\\Y\\T' => "$dir/A.php"], Bootstrap::classes(["$dir/A.php"]));
    }
}
