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
}

namespace RageKit\Generated {
    use RageKit\Screen;
    use RageKit\Tests\Slint\Runtime\AboutScreen;
    use RageKit\Tests\Slint\Runtime\CounterScreen;

    final class Screens
    {
        public static function name(Screen $screen): string
        {
            return $screen instanceof CounterScreen ? 'CounterScreen' : 'AboutScreen';
        }

        public static function values(Screen $screen): array
        {
            if ($screen instanceof CounterScreen) {
                return [1 => $screen->count, 2 => $screen->name, 3 => $screen->history];
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
            return 0;
        }
    }
}
