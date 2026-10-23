<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime {
    use RageKit\App;
    use RageKit\RootStore;
    use RageKit\Screen;
    use RageKit\Store;

    final class RuntimeSession extends Store
    {
        public string $userName = '';

        public function signOut(): void
        {
            $this->userName = '';
            $this->emit('signedOut');
        }
    }

    final class RuntimeStore extends RootStore
    {
        public RuntimeSession $session;

        public function __construct()
        {
            parent::__construct();
            $this->session = new RuntimeSession();
        }
    }

    /** The app under test: its first screen and start() are set by the test. */
    final class RuntimeApp extends App
    {
        public ?Screen $first = null;

        public ?\Closure $onStart = null;

        protected function firstScreen(): Screen
        {
            return $this->first ?? new CounterScreen();
        }

        protected function createStore(): RootStore
        {
            return new RuntimeStore();
        }

        protected function start(): void
        {
            if ($this->onStart !== null) {
                ($this->onStart)();
            }
        }

        protected function title(): string
        {
            return 'Main';
        }
    }

    final class CounterScreen extends Screen
    {
        public int $count = 0;
        public string $name = '';
        /** @var list<string> */
        public array $history = [];

        public function increment(): void
        {
            $this->count++;
            array_unshift($this->history, '+1 → ' . $this->count);
        }

        public function about(): void
        {
            $this->app->open(new AboutScreen(), title: 'About', width: 300, height: 100);
        }

        public function detail(): void
        {
            $this->navigator()->push(new DetailScreen($this->count));
        }

        public function signIn(string $name): void
        {
            RuntimeStore::get()->session->userName = $name;
        }

        public function signOut(): void
        {
            RuntimeStore::get()->session->signOut();
        }
    }

    final class AboutScreen extends Screen
    {
    }

    /** A screen pushed on top of CounterScreen (same window: Window itself). */
    final class DetailScreen extends Screen
    {
        public function __construct(public int $value = 0)
        {
        }

        public function bump(): void
        {
            $this->value++;
        }

        public function close(): void
        {
            $this->navigator()->back();
        }

        public function more(): void
        {
            $this->navigator()->push(new DetailScreen(100));
        }
    }

    /** Like dev mode after a restart: the first values() read puts the saved screens back on top. */
    final class RestoringScreen extends Screen
    {
        public bool $restored = false;
    }

    /** A screen in Window itself that tries to push one of a Window subclass. */
    final class WrongPushScreen extends Screen
    {
        public function go(): void
        {
            $this->navigator()->push(new FramedScreen());
        }
    }

    interface Titled
    {
        public function title(): string;
    }

    final class FramedScreen extends Screen implements Titled
    {
        public static function window(): string
        {
            return FrameWindow::class;
        }

        public function title(): string
        {
            return 'Framed';
        }
    }

    final class FrameWindow extends \RageKit\Window
    {
        public string $query = '';

        public function search(): void
        {
            $this->query = 'searched';
        }

        public function windowId(): int
        {
            return $this->id;
        }

        public function contextTitle(): string
        {
            return $this->context->title();
        }
    }
}

namespace RageKit\Generated {
    use RageKit\Screen;
    use RageKit\Tests\Slint\Runtime\AboutScreen;
    use RageKit\Tests\Slint\Runtime\CounterScreen;
    use RageKit\Tests\Slint\Runtime\DetailScreen;
    use RageKit\Tests\Slint\Runtime\FrameWindow;
    use RageKit\Tests\Slint\Runtime\FramedScreen;
    use RageKit\Tests\Slint\Runtime\RestoringScreen;
    use RageKit\Tests\Slint\Runtime\RuntimeStore;
    use RageKit\Tests\Slint\Runtime\WrongPushScreen;
    use RageKit\Window;

    /**
     * What ragekit would generate: window rk-screens = Counter (page 0), About (1), Detail (2),
     * WrongPush (3); window rk-screens-FrameWindow = Framed (page 0). Ids are unique per window.
     * Counter also shows the store's $store->session->userName (id 5).
     */
    final class Screens
    {
        public static function component(Screen $screen): string
        {
            return $screen instanceof FramedScreen ? 'rk-screens-FrameWindow' : 'rk-screens';
        }

        public static function page(Screen $screen): int
        {
            if ($screen instanceof AboutScreen) {
                return 1;
            }
            if ($screen instanceof DetailScreen) {
                return 2;
            }
            return $screen instanceof WrongPushScreen ? 3 : 0;
        }

        public static function newWindow(Screen $screen): Window
        {
            return $screen instanceof FramedScreen ? new FrameWindow() : new Window();
        }

        public static function values(Window $w): array
        {
            $n = $w->navigator();
            $root = $n->stack()[0];
            if ($root instanceof RestoringScreen && !$root->restored) {
                $root->restored = true;
                $n->push(new DetailScreen(7));
            }
            $s = $n->top();
            if ($s instanceof CounterScreen) {
                return [1 => $s->count, 2 => $s->name, 3 => $s->history, 5 => RuntimeStore::get()->session->userName];
            }
            if ($s instanceof DetailScreen) {
                return [4 => $s->value];
            }
            if ($s instanceof FramedScreen) {
                if ($w instanceof FrameWindow) {
                    return [1 => $s->title(), 2 => $w->query, 3 => $w->maximized];
                }
                return [];
            }
            return [];
        }

        public static function dispatch(Window $w, int $action, string $arg): int
        {
            $s = $w->navigator()->top();
            if ($s instanceof CounterScreen) {
                switch ($action) {
                    case 1:
                        $s->increment();
                        return 0;
                    case 2:
                        $s->name = $arg;
                        return 2;
                    case 3:
                        $s->about();
                        return 0;
                    case 4:
                        $s->detail();
                        return 0;
                    case 9:
                        $s->signIn($arg);
                        return 0;
                    case 10:
                        $s->signOut();
                        return 0;
                    default:
                        return 0;
                }
            }
            if ($s instanceof DetailScreen) {
                switch ($action) {
                    case 5:
                        $s->bump();
                        return 0;
                    case 6:
                        $s->close();
                        return 0;
                    case 8:
                        $s->more();
                        return 0;
                    default:
                        return 0;
                }
            }
            if ($s instanceof WrongPushScreen) {
                switch ($action) {
                    case 7:
                        $s->go();
                        return 0;
                    default:
                        return 0;
                }
            }
            if ($s instanceof FramedScreen) {
                if ($w instanceof FrameWindow) {
                    switch ($action) {
                        case 1:
                            $w->close();
                            return 0;
                        case 2:
                            $w->query = $arg;
                            return 2;
                        case 3:
                            $w->search();
                            return 0;
                        default:
                            return 0;
                    }
                }
            }
            return 0;
        }
    }
}
