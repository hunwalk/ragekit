<?php

namespace Demo;

use RageKit\Screen;

/** Every core element in one window, grouped in tabs. */
final class KitchenSinkScreen extends Screen
{
    public const FRUITS = ['Apple', 'Banana', 'Cherry', 'Dragon fruit'];

    public int $tab = 0;
    public string $status = 'Ready';
    public string $name = '';
    public bool $subscribed = false;
    public float $volume = 40.0;
    public string $fruit = '';

    public static function template(): string
    {
        return 'templates/KitchenSink.rk';
    }

    public function volumeText(): string
    {
        return 'Volume: ' . (int) round($this->volume);
    }

    public function progress(): float
    {
        return $this->volume / 100;
    }

    public function summary(): string
    {
        $who = $this->name === '' ? 'Anonymous' : $this->name;
        $fruit = $this->fruit === '' ? 'no fruit' : $this->fruit;
        return $who . ' · ' . $fruit . ' · ' . ($this->subscribed ? 'subscribed' : 'not subscribed');
    }

    public function press(string $variant): void
    {
        $this->status = 'Clicked the ' . $variant . ' button';
    }
}
