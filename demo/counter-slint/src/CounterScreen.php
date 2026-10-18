<?php

namespace Demo;

use RageKit\Screen;

final class CounterScreen extends Screen
{
    public int $count = 0;
    public string $name = '';
    public bool $showHistory = true;
    /** @var list<string> newest first */
    public array $history = [];

    public static function template(): string
    {
        return 'templates/CounterScreen.rk';
    }

    public function greeting(): string
    {
        return $this->name === '' ? 'Type your name above' : 'Hello, ' . $this->name . '!';
    }

    public function increment(): void
    {
        $this->count++;
        $this->log('+1 → ' . $this->count);
    }

    public function decrement(): void
    {
        $this->count--;
        $this->log('−1 → ' . $this->count);
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->log('reset');
    }

    public function about(): void
    {
        $this->app->open(new AboutScreen(), title: 'About RageKit', width: 420, height: 120);
    }

    private function log(string $entry): void
    {
        array_unshift($this->history, $entry);
        $this->history = array_slice($this->history, 0, 8);
    }
}
