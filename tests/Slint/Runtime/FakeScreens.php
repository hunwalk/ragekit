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
    }

    final class AboutScreen extends Screen
    {
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
    use RageKit\Screen;
    use RageKit\Tests\Slint\Runtime\AboutScreen;
    use RageKit\Layout;
    use RageKit\Tests\Slint\Runtime\CounterScreen;
    use RageKit\Tests\Slint\Runtime\FrameLayout;
    use RageKit\Tests\Slint\Runtime\FramedScreen;

    final class Screens
    {
        public static function name(Screen $screen): string
        {
            if ($screen instanceof FramedScreen) {
                return 'FramedScreen';
            }
            return $screen instanceof CounterScreen ? 'CounterScreen' : 'AboutScreen';
        }

        public static function layout(Screen $screen): ?Layout
        {
            return $screen instanceof FramedScreen ? new FrameLayout() : null;
        }

        public static function values(Screen $screen): array
        {
            if ($screen instanceof CounterScreen) {
                return [1 => $screen->count, 2 => $screen->name, 3 => $screen->history];
            }
            if ($screen instanceof FramedScreen) {
                $l = $screen->layoutObject();
                if ($l instanceof FrameLayout) {
                    return [1 => $screen->title(), 2 => $l->query, 3 => $l->maximized];
                }
                return [];
            }
            return [];
        }

        public static function dispatch(Screen $screen, int $action, string $arg): int
        {
            if ($screen instanceof CounterScreen) {
                switch ($action) {
                    case 1:
                        $screen->increment();
                        return 0;
                    case 2:
                        $screen->name = $arg;
                        return 2;
                    case 3:
                        $screen->about();
                        return 0;
                    default:
                        return 0;
                }
            }
            if ($screen instanceof FramedScreen) {
                $l = $screen->layoutObject();
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
