<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime {
    use RageKit\Screen;

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
    }

    final class AboutScreen extends Screen
    {
    }

    /** A screen pushed on top of CounterScreen (same window: no layout). */
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

    /** A screen without a layout that tries to push one with a layout. */
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
        public static function layout(): string
        {
            return FrameLayout::class;
        }

        public function title(): string
        {
            return 'Framed';
        }
    }

    final class FrameLayout extends \RageKit\Layout
    {
        public string $query = '';

        public function search(): void
        {
            $this->query = 'searched';
        }

        public function windowId(): int
        {
            return $this->window;
        }

        public function contextTitle(): string
        {
            return $this->context->title();
        }
    }
}

namespace RageKit\Generated {
    use RageKit\Layout;
    use RageKit\Navigator;
    use RageKit\Screen;
    use RageKit\Tests\Slint\Runtime\AboutScreen;
    use RageKit\Tests\Slint\Runtime\CounterScreen;
    use RageKit\Tests\Slint\Runtime\DetailScreen;
    use RageKit\Tests\Slint\Runtime\FrameLayout;
    use RageKit\Tests\Slint\Runtime\FramedScreen;
    use RageKit\Tests\Slint\Runtime\RestoringScreen;
    use RageKit\Tests\Slint\Runtime\WrongPushScreen;

    /**
     * What ragekit would generate: window rk-screens = Counter (page 0), About (1), Detail (2),
     * WrongPush (3); window rk-screens-FrameLayout = Framed (page 0). Ids are unique per window.
     */
    final class Screens
    {
        public static function window(Screen $screen): string
        {
            return $screen instanceof FramedScreen ? 'rk-screens-FrameLayout' : 'rk-screens';
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

        public static function layout(Screen $screen): ?Layout
        {
            return $screen instanceof FramedScreen ? new FrameLayout() : null;
        }

        public static function values(Navigator $n): array
        {
            $root = $n->stack()[0];
            if ($root instanceof RestoringScreen && !$root->restored) {
                $root->restored = true;
                $n->push(new DetailScreen(7));
            }
            $s = $n->top();
            if ($s instanceof CounterScreen) {
                return [1 => $s->count, 2 => $s->name, 3 => $s->history];
            }
            if ($s instanceof DetailScreen) {
                return [4 => $s->value];
            }
            if ($s instanceof FramedScreen) {
                $l = $n->layout();
                if ($l instanceof FrameLayout) {
                    return [1 => $s->title(), 2 => $l->query, 3 => $l->maximized];
                }
                return [];
            }
            return [];
        }

        public static function dispatch(Navigator $n, int $action, string $arg): int
        {
            $s = $n->top();
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
                $l = $n->layout();
                if ($l instanceof FrameLayout) {
                    switch ($action) {
                        case 1:
                            $l->close();
                            return 0;
                        case 2:
                            $l->query = $arg;
                            return 2;
                        case 3:
                            $l->search();
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
