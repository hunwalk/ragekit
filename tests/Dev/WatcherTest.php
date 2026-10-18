<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Dev\Watcher;

final class WatcherTest extends TestCase
{
    private function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/rk-watch-' . uniqid();
        mkdir($dir);
        return $dir;
    }

    public function testBurstOfChangesIsOneBatch(): void
    {
        $dir = $this->tmp();
        file_put_contents("$dir/a.rk", '1');
        $w = new Watcher(["$dir/a.rk" => 'template', "$dir/b.php" => 'php']);
        self::assertSame([], $w->poll());
        file_put_contents("$dir/a.rk", '2');
        file_put_contents("$dir/b.php", '<?php');
        self::assertSame([], $w->poll(), 'not stable for 150 ms yet');
        file_put_contents("$dir/a.rk", '3'); // saved again within the same second
        usleep(100_000);
        self::assertSame([], $w->poll(), 'the second save restarts the wait');
        usleep(200_000);
        self::assertSame(['template' => ["$dir/a.rk"], 'php' => ["$dir/b.php"]], $w->poll());
        self::assertSame([], $w->poll());
    }

    public function testSettledFileWaitsForOneStillBeingWritten(): void
    {
        $dir = $this->tmp();
        file_put_contents("$dir/a.rk", '1');
        $w = new Watcher(["$dir/a.rk" => 'template', "$dir/b.php" => 'php']);
        file_put_contents("$dir/b.php", '<?php');
        $w->poll();
        usleep(200_000); // b.php has settled
        file_put_contents("$dir/a.rk", '2');
        self::assertSame([], $w->poll(), 'a.rk is still new');
        usleep(200_000);
        self::assertSame(['template' => ["$dir/a.rk"], 'php' => ["$dir/b.php"]], $w->poll());
    }

    public function testSameContentIsNoChange(): void
    {
        $dir = $this->tmp();
        file_put_contents("$dir/a.rk", '1');
        $w = new Watcher(["$dir/a.rk" => 'template']);
        touch("$dir/a.rk", time() + 5);
        usleep(200_000);
        $w->poll();
        usleep(200_000);
        self::assertSame([], $w->poll());
    }

    public function testDeletedFileIsAChange(): void
    {
        $dir = $this->tmp();
        file_put_contents("$dir/a.php", '<?php');
        $w = new Watcher(["$dir/a.php" => 'php']);
        unlink("$dir/a.php");
        $w->poll();
        usleep(200_000);
        self::assertSame(['php' => ["$dir/a.php"]], $w->poll());
    }

    public function testSetFilesKeepsKnownFilesAndWatchesNewOnes(): void
    {
        $dir = $this->tmp();
        file_put_contents("$dir/a.rk", '1');
        $w = new Watcher(["$dir/a.rk" => 'template']);
        file_put_contents("$dir/b.rk", 'new');
        $w->setFiles(["$dir/a.rk" => 'template', "$dir/b.rk" => 'template']);
        usleep(200_000);
        self::assertSame([], $w->poll(), 'a file added to the watch list is not itself a change');
        file_put_contents("$dir/b.rk", 'edited');
        $w->poll();
        usleep(200_000);
        self::assertSame(['template' => ["$dir/b.rk"]], $w->poll());
    }
}
