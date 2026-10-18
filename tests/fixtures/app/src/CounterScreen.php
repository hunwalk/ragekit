<?php

namespace Demo;

use RageKit\Screen;

final class CounterScreen extends Screen
{
    private const LIMIT = 8;
    public const KEYS = [['1', '2'], ['3']];

    public int $count = 0;
    public bool $showHistory = true;
    /** @var list<string> */
    public array $history = [];
    private Engine $engine;

    public static function template(): string
    {
        return 'templates/counter.rk';
    }

    public function greeting(): string
    {
        return 'hi';
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function press(string $key): void
    {
    }
}
