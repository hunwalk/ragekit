<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\Project\SourceFiles;

final class SourceFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Paths::normalize(sys_get_temp_dir()) . '/rk-sources-' . uniqid();
        mkdir("$this->dir/src/native", 0777, true);
        foreach (['main.php', 'src/A.php', 'src/native/x.stub.php', 'src/native/x.cc', 'src/native/y.h', 'src/B.rk'] as $file) {
            file_put_contents("$this->dir/$file", '');
        }
    }

    public function testPhpExpandsDirectoriesAndSkipsStubs(): void
    {
        self::assertSame(
            ["$this->dir/main.php", "$this->dir/src/A.php"],
            SourceFiles::php(["$this->dir/main.php", "$this->dir/src"]),
        );
    }

    public function testCppFindsCcFiles(): void
    {
        self::assertSame(["$this->dir/src/native/x.cc"], SourceFiles::cpp(["$this->dir/src"]));
    }
}
