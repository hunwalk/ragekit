<?php

declare(strict_types=1);

namespace RageKit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use RageKit\Navigator;
use RageKit\RootStore;
use RageKit\Slint\Dev\Bindings;
use RageKit\Slint\Dev\Channel;
use RageKit\Slint\Dev\Protocol;
use RageKit\Slint\Dev\Session;
use RageKit\Store;
use RageKit\Tests\Core\RecordingApp;
use RageKit\Window;

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

/** Pushed on top of DevFixtureScreen (same window). */
final class DevDetailScreen extends \RageKit\Screen
{
    public int $zoom = 1;
}

/** Can't be re-created after a restart: its constructor needs an argument. */
final class DevArgScreen extends \RageKit\Screen
{
    public function __construct(public string $id)
    {
    }
}

final class DevFramedScreen extends \RageKit\Screen
{
    public string $refreshed = '';

    public function title(): string
    {
        return 'T';
    }

    public function refresh(string $why): void
    {
        $this->refreshed = $why;
    }
}

final class DevSessionStore extends Store
{
    public string $userName = '';
    public bool $dark = false;

    public function signIn(string $name): void
    {
        $this->userName = $name;
    }

    public function signedIn(): bool
    {
        return $this->userName !== '';
    }
}

final class DevStore extends RootStore
{
    public DevSessionStore $session;

    public function __construct()
    {
        parent::__construct();
        $this->session = new DevSessionStore();
    }
}

final class DevFrameWindow extends Window
{
    public string $query = '';

    public function search(): void
    {
        $this->query = 'searched';
    }
}

/** The dev runtime's PHP pieces (src/Slint/Dev), without the shell. */
final class RuntimeTest extends TestCase
{
    private const JSON = '{"windows":[{"name":"rk-screens","window":null,"frameless":false,"values":[],"actions":[],"pages":['
        . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevFixtureScreen","name":"DevFixtureScreen","page":0,'
        . '"values":[{"id":1,"owner":"screen","source":"property","name":"count","type":"int"},{"id":2,"owner":"screen","source":"method","name":"label","type":"string"},{"id":3,"owner":"screen","source":"property","name":"items","type":"list"}],'
        . '"actions":[{"id":1,"owner":"screen","kind":"set","target":"count","binding":1,"type":"int"},{"id":2,"owner":"screen","kind":"call","target":"add","args":[["literal","5"],["arg","k"]],"params":["int","string"]}]},'
        . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevDetailScreen","name":"DevDetailScreen","page":1,'
        . '"values":[{"id":4,"owner":"screen","source":"property","name":"zoom","type":"int"}],'
        . '"actions":[{"id":3,"owner":"screen","kind":"set","target":"zoom","binding":4,"type":"int"}]},'
        . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevArgScreen","name":"DevArgScreen","page":2,"values":[],"actions":[]}]}]}';

    private function nav(Bindings $b, \RageKit\Screen $root, ?Window $window = null): Navigator
    {
        $window ??= $b->newWindow($root);
        $window->attach(RecordingApp::make(), 1);
        return new Navigator($window, $root, fn(\RageKit\Screen $s): string => $b->component($s));
    }

    public function testBindingsReadValuesAndDispatchLikeTheGeneratedBinder(): void
    {
        $b = Bindings::load(self::JSON);
        $s = new DevFixtureScreen();
        $s->items = [1, 2];
        $n = $this->nav($b, $s);
        self::assertSame('rk-screens', $b->component($s));
        self::assertSame(0, $b->page($s));
        self::assertSame(1, $b->dispatch($n->window(), 1, '7'));
        self::assertSame(0, $b->dispatch($n->window(), 2, 'x'));
        self::assertSame(0, $b->dispatch($n->window(), 99, 'x'), 'unknown action');
        self::assertSame([1 => 12, 2 => 'count 12 x', 3 => ['1', '2']], $b->values($n->window()));
    }

    /** A bound image source reaches SlintWindow as an ImageValue, as the generated binder gives it. */
    public function testImageValuesInDev(): void
    {
        $json = str_replace('"name":"label","type":"string"', '"name":"label","type":"image"', self::JSON);
        $b = Bindings::load($json);
        $s = new DevFixtureScreen();
        $n = $this->nav($b, $s);
        $value = $b->values($n->window())[2];
        self::assertInstanceOf(\RageKit\Slint\ImageValue::class, $value);
        self::assertSame('count 0 ', $value->source);
    }

    /** Review Focus 1 in dev: another page's action does nothing. */
    public function testOtherPagesActionsAreIgnored(): void
    {
        $b = Bindings::load(self::JSON);
        $root = new DevFixtureScreen();
        $n = $this->nav($b, $root);
        $detail = new DevDetailScreen();
        $n->push($detail);
        self::assertSame(1, $b->page($detail));
        self::assertSame([4 => 1], $b->values($n->window()));
        self::assertSame(0, $b->dispatch($n->window(), 1, '7'));
        self::assertSame(0, $root->count);
        self::assertSame(4, $b->dispatch($n->window(), 3, '2'));
        self::assertSame(2, $detail->zoom);
    }

    public function testPageZeroIsRkPage(): void
    {
        self::assertSame('rk-page', Session::property(0));
        self::assertSame('p7', Session::property(7));
    }

    private const FRAMED = '{"windows":[{"name":"rk-screens-DevFrameWindow","window":"RageKit\\\\Tests\\\\Dev\\\\DevFrameWindow","frameless":true,'
        . '"values":[{"id":1,"owner":"context","source":"method","name":"title","type":"string"},{"id":2,"owner":"window","source":"property","name":"query","type":"string"}],'
        . '"actions":[{"id":1,"owner":"window","kind":"set","target":"query","binding":2,"type":"string"},'
        . '{"id":2,"owner":"window","kind":"call","target":"search","args":[],"params":[]},'
        . '{"id":3,"owner":"context","kind":"call","target":"refresh","args":[["literal","bar"]],"params":["string"]}],'
        . '"pages":[{"class":"RageKit\\\\Tests\\\\Dev\\\\DevFramedScreen","name":"DevFramedScreen","page":0,"values":[],"actions":[]}]}]}';

    public function testWindowValuesAndActionsFromScreensJson(): void
    {
        $b = Bindings::load(self::FRAMED);
        $s = new DevFramedScreen();
        $l = $b->newWindow($s);
        self::assertInstanceOf(DevFrameWindow::class, $l);
        self::assertSame(Window::class, get_class(Bindings::load(self::JSON)->newWindow(new DevFixtureScreen())));
        $n = $this->nav($b, $s, $l);
        self::assertSame([1 => 'T', 2 => ''], $b->values($n->window()));
        self::assertSame(2, $b->dispatch($n->window(), 1, 'abc'));
        self::assertSame('abc', $l->query);
        $b->dispatch($n->window(), 2, '');
        self::assertSame('searched', $l->query);
        $b->dispatch($n->window(), 3, '');
        self::assertSame('bar', $s->refreshed);
    }

    public function testWindowStateSurvivesRestart(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::FRAMED, $state, restart: false);
        $s = new DevFramedScreen();
        $l = new DevFrameWindow();
        $l->query = 'abc';
        $this->nav(Bindings::load(self::FRAMED), $s, $l);
        Session::pending($s);
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();
        $data = json_decode((string) file_get_contents($state), true);
        self::assertSame(['query' => 'abc', 'maximized' => false], $data['windows'][0]['windowProps'], 'not its id: the backend gives that');

        Session::start($this->channel(), self::FRAMED, $state, restart: true);
        $fresh = Session::windowFor(new DevFramedScreen());
        self::assertInstanceOf(DevFrameWindow::class, $fresh);
        self::assertSame('abc', $fresh->query);
    }

    private const STORE = '{"windows":[{"name":"rk-screens","window":null,"frameless":false,'
        . '"values":[{"id":1,"owner":"store","source":"property","name":"session.userName","type":"string"},{"id":2,"owner":"store","source":"method","name":"session.signedIn","type":"bool"},{"id":3,"owner":"store","source":"property","name":"session.dark","type":"bool"}],'
        . '"actions":[{"id":1,"owner":"store","kind":"call","target":"session.signIn","args":[["expr",0]],"params":["string"]},'
        . '{"id":2,"owner":"store","kind":"set","target":"session.dark","binding":3,"type":"bool"}],"pages":['
        . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevFixtureScreen","name":"DevFixtureScreen","page":0,"values":[],"actions":[]},'
        . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevDetailScreen","name":"DevDetailScreen","page":1,"values":[],"actions":[]}]}]}';

    public function testStoreValuesAndActionsInDev(): void
    {
        $store = new DevStore();
        $b = Bindings::load(self::STORE);
        $n = $this->nav($b, new DevFixtureScreen());
        self::assertSame([1 => '', 2 => false, 3 => false], $b->values($n->window()));
        self::assertSame(0, $b->dispatch($n->window(), 1, 'ann'));
        self::assertSame(3, $b->dispatch($n->window(), 2, '1'));
        self::assertSame('ann', $store->session->userName);
        self::assertTrue($store->session->dark);
        self::assertSame([1 => 'ann', 2 => true, 3 => true], $b->values($n->window()));
    }

    public function testSnapshotKeepsStores(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::STORE, $state, restart: false);
        $store = new DevStore();
        $store->session->userName = 'ann';
        Session::pending(new DevFixtureScreen());
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();
        $data = json_decode((string) file_get_contents($state), true);
        self::assertSame(['session' => ['userName' => 'ann', 'dark' => false]], $data['stores']);
    }

    /** Review Focus 5: a PHP restart while signed in and two screens deep: still signed in, same screen. */
    public function testRestartRestoresStoresBeforeTheFirstSync(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::STORE, $state, restart: false);
        $store = new DevStore();
        $store->session->userName = 'ann';
        $b = Bindings::load(self::STORE);
        $root = new DevFixtureScreen();
        $n = $this->nav($b, $root);
        $n->push(new DevDetailScreen());
        Session::pending($root);
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();

        Session::start($this->channel(), self::STORE, $state, restart: true);
        new DevStore();                                 // the app's createStore(), fresh
        $fresh = new DevFixtureScreen();
        $window = Session::windowFor($fresh);           // just before the main window opens
        self::assertSame('ann', DevStore::get()->session->userName);
        $window->attach(RecordingApp::make(), 1);
        $n2 = new Navigator($window, $fresh, fn(\RageKit\Screen $s): string => $b->component($s));
        Session::restoreStack($n2);                     // the first values() read
        self::assertSame(2, $n2->depth());
        self::assertInstanceOf(DevDetailScreen::class, $n2->top());
        self::assertSame([1 => 'ann', 2 => true, 3 => false], $b->values($window));
    }

    /** A store action of the sign-in page (as the compiler now places it) does nothing once another page shows. */
    public function testPageStoreActionOfAnotherPageIsIgnored(): void
    {
        $json = '{"windows":[{"name":"rk-screens","window":null,"frameless":false,"values":[],"actions":[],"pages":['
            . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevFixtureScreen","name":"DevFixtureScreen","page":0,"values":[],'
            . '"actions":[{"id":1,"owner":"store","kind":"call","target":"session.signIn","args":[["expr",0]],"params":["string"]}]},'
            . '{"class":"RageKit\\\\Tests\\\\Dev\\\\DevDetailScreen","name":"DevDetailScreen","page":1,"values":[],"actions":[]}]}]}';
        $store = new DevStore();
        $b = Bindings::load($json);
        $n = $this->nav($b, new DevFixtureScreen());
        $b->dispatch($n->window(), 1, 'ann');
        self::assertSame('ann', $store->session->userName);
        $n->push(new DevDetailScreen());
        $b->dispatch($n->window(), 1, 'bob');
        self::assertSame('ann', $store->session->userName);
    }

    /** A store that is gone, or a property whose type changed, is skipped like a screen's. */
    public function testStoreRestoreSkipsWhatChanged(): void
    {
        $state = $this->tmp() . '/state.json';
        file_put_contents($state, json_encode(['windows' => [], 'stores' => ['session' => ['userName' => 5, 'dark' => true], 'gone' => ['x' => 1]]]));
        Session::start($this->channel(), self::STORE, $state, restart: true);
        new DevStore();
        Session::windowFor(new DevFixtureScreen());
        self::assertSame('', DevStore::get()->session->userName);
        self::assertTrue(DevStore::get()->session->dark);
    }

    public function testExpressionArgumentsAreSplit(): void
    {
        $json = '{"windows":[{"name":"rk-screens","window":null,"frameless":false,"values":[],"actions":[],"pages":[{"class":"RageKit\\\\Tests\\\\Dev\\\\DevFixtureScreen","name":"DevFixtureScreen","page":0,"values":[],'
            . '"actions":[{"id":1,"owner":"screen","kind":"call","target":"add","args":[["expr",0],["expr",1]],"params":["int","string"]}]}]}]}';
        $s = new DevFixtureScreen();
        $b = Bindings::load($json);
        $b->dispatch($this->nav($b, $s)->window(), 1, "3\x1Fk");
        self::assertSame(3, $s->count);
        self::assertSame('k', $s->last);
    }

    public function testUnknownScreenThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No template for ' . DevOtherScreen::class);
        Bindings::load(self::JSON)->component(new DevOtherScreen());
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

    public function testSnapshotKeepsTheStack(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::JSON, $state, restart: false);
        $b = Bindings::load(self::JSON);
        $root = new DevFixtureScreen();
        $root->count = 2;
        $n = $this->nav($b, $root);
        $detail = new DevDetailScreen();
        $detail->zoom = 3;
        $n->push($detail);
        Session::pending($root);
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();
        $saved = json_decode((string) file_get_contents($state), true)['windows'][0];
        self::assertSame(DevFixtureScreen::class, $saved['class']);
        self::assertSame(2, $saved['props']['count']);
        self::assertSame([['class' => DevDetailScreen::class, 'props' => ['zoom' => 3]]], $saved['stack']);
    }

    /** After reset() (or replace() of the first screen) a restart brings back the new first screen, not the one the window opened with. */
    public function testRestartAfterResetRestoresTheNewRoot(): void
    {
        $state = $this->tmp() . '/state.json';
        Session::start($this->channel(), self::JSON, $state, restart: false);
        $b = Bindings::load(self::JSON);
        $opened = new DevFixtureScreen();
        $n = $this->nav($b, $opened);
        $detail = new DevDetailScreen();
        $detail->zoom = 5;
        $n->reset($detail);
        Session::pending($opened);
        Session::opened(1, 'Main', 300, 200);
        Session::markRunning();
        Session::snapshot();

        Session::start($this->channel(), self::JSON, $state, restart: true);
        $fresh = new DevFixtureScreen();       // main() opens the window with its first screen again
        Session::restore($fresh);
        $n2 = $this->nav($b, $fresh);
        Session::restoreStack($n2);
        self::assertSame(1, $n2->depth());
        self::assertInstanceOf(DevDetailScreen::class, $n2->top());
        self::assertSame(5, $n2->top()->zoom);
    }

    public function testRestoreRebuildsTheStack(): void
    {
        $state = $this->tmp() . '/state.json';
        file_put_contents($state, json_encode(['windows' => [['window' => 1, 'class' => DevFixtureScreen::class, 'title' => 'M', 'width' => 1, 'height' => 1,
            'props' => ['count' => 2], 'stack' => [['class' => DevDetailScreen::class, 'props' => ['zoom' => 3]]]]]]));
        Session::start($this->channel(), self::JSON, $state, restart: true);
        $root = new DevFixtureScreen();
        Session::restore($root);
        $n = $this->nav(Bindings::load(self::JSON), $root);
        Session::restoreStack($n);
        self::assertSame(2, $root->count);
        self::assertSame(2, $n->depth());
        self::assertInstanceOf(DevDetailScreen::class, $n->top());
        self::assertSame(3, $n->top()->zoom);
        Session::restoreStack($n);
        self::assertSame(2, $n->depth(), 'only once');
    }

    public function testRestoreStopsAtAScreenNeedingArguments(): void
    {
        $state = $this->tmp() . '/state.json';
        file_put_contents($state, json_encode(['windows' => [['window' => 1, 'class' => DevFixtureScreen::class, 'title' => 'M', 'width' => 1, 'height' => 1,
            'props' => [], 'stack' => [['class' => DevArgScreen::class, 'props' => ['id' => 'x']], ['class' => DevDetailScreen::class, 'props' => []]]]]]));
        Session::start($this->channel(), self::JSON, $state, restart: true);
        $root = new DevFixtureScreen();
        Session::restore($root);
        $n = $this->nav(Bindings::load(self::JSON), $root);
        Session::restoreStack($n);
        self::assertSame(1, $n->depth(), 'DevArgScreen needs a constructor argument: the stack stops at the root');
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
