<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Slint\Dev\Bindings;
use RageKit\Slint\Dev\Channel;
use RageKit\Slint\Dev\Protocol;
use RageKit\Slint\Dev\Session;

foreach (['Protocol', 'Channel', 'Bindings', 'Session'] as $file) {
    require_once __DIR__ . "/../../src/Slint/Dev/$file.php";
}

final class DevFixtureScreen extends \RageKit\Screen
{
    public int $count = 0;
    public array $items = [];
    public string $last = '';

    public function label(): string
    {
        return "count {$this->count} {$this->last}";
    }

    public function add(int $n, string $k): void
    {
        $this->count += $n;
        $this->last = $k;
    }
}

final class DevOtherScreen extends \RageKit\Screen
{
}

/** The dev runtime's PHP pieces (src/Slint/Dev), without the shell. */
final class RuntimeTest extends TestCase
{
    private const JSON = '{"screens":[{"class":"RageKit\\\\Tests\\\\Dev\\\\DevFixtureScreen","name":"DevFixtureScreen",'
        . '"values":[{"id":1,"source":"property","name":"count","type":"int"},{"id":2,"source":"method","name":"label","type":"string"},{"id":3,"source":"property","name":"items","type":"list"}],'
        . '"actions":[{"id":1,"kind":"set","target":"count","binding":1,"type":"int"},{"id":2,"kind":"call","target":"add","args":[["literal","5"],["arg","k"]],"params":["int","string"]}]}]}';

    public function testBindingsReadValuesAndDispatchLikeTheGeneratedBinder(): void
    {
        $b = Bindings::load(self::JSON);
        $s = new DevFixtureScreen();
        $s->items = [1, 2];
        self::assertSame('DevFixtureScreen', $b->name($s));
        self::assertSame(1, $b->dispatch($s, 1, '7'));
        self::assertSame(0, $b->dispatch($s, 2, 'x'));
        self::assertSame(0, $b->dispatch($s, 99, 'x'), 'unknown action');
        self::assertSame([1 => 12, 2 => 'count 12 x', 3 => ['1', '2']], $b->values($s));
    }

    public function testUnknownScreenThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No template for ' . DevOtherScreen::class);
        Bindings::load(self::JSON)->name(new DevOtherScreen());
    }

    public function testChannelQueuesEventsArrivingBeforeAReply(): void
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, Protocol::encode('event', '1', '3', 'a') . Protocol::encode('opened', '2'));
        rewind($in);
        $out = fopen('php://memory', 'w+');
        $channel = new Channel($in, $out);
        self::assertSame(['opened', '2'], $channel->request('open', 'S', 'T', '1', '1'));
        self::assertSame(['event', '1', '3', 'a'], $channel->next());
        self::assertNull($channel->next());
        rewind($out);
        self::assertSame(Protocol::encode('open', 'S', 'T', '1', '1'), stream_get_contents($out));
    }

    public function testSnapshotAndRestoreSkipChangedProperties(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::JSON, $state, restart: false);
        $s = new DevFixtureScreen();
        $s->count = 4;
        $s->items = ['a'];
        Session::pending($s);
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();
        $data = json_decode((string) file_get_contents($state), true);
        self::assertSame(['count' => 4, 'items' => ['a'], 'last' => ''], $data['windows'][0]['props']);
        $data['windows'][0]['props']['gone'] = 1;
        $data['windows'][0]['props']['items'] = 'not-an-array';
        file_put_contents($state, json_encode($data));

        Session::start($this->channel(), self::JSON, $state, restart: true);
        $fresh = new DevFixtureScreen();
        Session::restore($fresh);
        self::assertSame(4, $fresh->count);
        self::assertSame([], $fresh->items, 'a value of the wrong type is skipped');
    }

    public function testNoRestoreWithoutRestart(): void
    {
        $state = $this->tmp() . '/state.json';
        file_put_contents($state, json_encode(['windows' => [['window' => 1, 'class' => DevFixtureScreen::class, 'title' => 'M', 'width' => 1, 'height' => 1, 'props' => ['count' => 9]]]]));
        Session::start($this->channel(), self::JSON, $state, restart: false);
        $fresh = new DevFixtureScreen();
        Session::restore($fresh);
        self::assertSame(0, $fresh->count);
        self::assertSame([], Session::leftovers());
    }

    public function testLeftoverWindowsComeBackInOrder(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::JSON, $state, restart: false);
        Session::pending(new DevFixtureScreen());
        Session::opened(1, 'Main', 300, 200);
        Session::pending(new DevOtherScreen());
        Session::opened(2, 'About', 200, 120);
        Session::markRunning();
        Session::snapshot();

        Session::start($this->channel(), self::JSON, $state, restart: true);
        Session::restore(new DevFixtureScreen());
        self::assertSame([['class' => DevOtherScreen::class, 'title' => 'About', 'width' => 200, 'height' => 120]], Session::leftovers());
    }

    public function testSnapshotWaitsUntilRunSoUnreopenedWindowsSurvive(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::JSON, $state, restart: false);
        Session::pending(new DevFixtureScreen());
        Session::opened(1, 'Main', 300, 200);
        Session::pending(new DevOtherScreen());
        Session::opened(2, 'About', 200, 120);
        Session::markRunning();
        Session::snapshot();

        // Restarted: main() reopens Main, then PHP dies before About is reopened (e.g. a parse error in it).
        Session::start($this->channel(), self::JSON, $state, restart: true);
        $main = new DevFixtureScreen();
        Session::restore($main);
        Session::pending($main);
        Session::opened(1, 'Main', 300, 200);
        Session::snapshot();
        $classes = array_column(json_decode((string) file_get_contents($state), true)['windows'], 'class');
        self::assertSame([DevFixtureScreen::class, DevOtherScreen::class], $classes, 'About stays in the snapshot');
    }

    public function testSnapshotReplacesTheFileAtomically(): void
    {
        $dir = $this->tmp();
        $state = "$dir/state.json";
        file_put_contents($state, '{"windows":[]}');
        chmod($state, 0444); // a plain overwrite can't write it; a rename over it can
        Session::start($this->channel(), self::JSON, $state, restart: false);
        Session::pending(new DevFixtureScreen());
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();
        self::assertCount(1, json_decode((string) file_get_contents($state), true)['windows']);
        self::assertSame(['state.json'], array_values(array_diff(scandir($dir), ['.', '..'])), 'no temp file left');
    }

    public function testLeftoversThatCantBeReopenedAreSkipped(): void
    {
        $state = $this->tmp() . '/state.json';
        file_put_contents($state, json_encode(['windows' => [
            ['window' => 1, 'class' => 'Gone\\Deleted', 'title' => 'Gone', 'width' => 1, 'height' => 1, 'props' => []],
            ['window' => 2, 'class' => DevOtherScreen::class, 'title' => 'About', 'width' => 200, 'height' => 120, 'props' => []],
        ]]));
        Session::start($this->channel(), self::JSON, $state, restart: true);
        $opened = [];
        $problems = Session::reopenLeftovers(function (object $screen, string $title) use (&$opened): void {
            $opened[] = [get_class($screen), $title];
        });
        self::assertSame([[DevOtherScreen::class, 'About']], $opened);
        self::assertCount(1, $problems);
        self::assertStringContainsString('Gone\\Deleted', $problems[0]);
    }

    private function channel(): Channel
    {
        return new Channel(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'));
    }

    private function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/rk-runtime-' . uniqid();
        mkdir($dir);
        return $dir;
    }
}
