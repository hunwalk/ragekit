<?php

namespace Demo;

use RageKit\Screen;

/** Every core element in one window, grouped in tabs. */
final class KitchenSinkScreen extends Screen
{
    public const FRUITS = ['Apple', 'Banana', 'Cherry', 'Dragon fruit'];
    public const SIZES = ['Small', 'Medium', 'Large'];

    public int $tab = 0;
    public string $status = 'Ready';
    public string $name = '';
    public bool $subscribed = false;
    public float $volume = 40.0;
    public string $fruit = '';
    public bool $notifications = true;
    public string $notes = '';
    public string $size = 'Medium';
    public bool $bold = false;

    public static function template(): string
    {
        return 'templates/KitchenSink.rk';
    }

    public static function layout(): string
    {
        return KitchenSinkLayout::class;
    }

    public function preferences(): string
    {
        return $this->size . ' · ' . ($this->bold ? 'bold' : 'regular') . ' · notifications ' . ($this->notifications ? 'on' : 'off')
            . ' · ' . strlen($this->notes) . ' characters of notes';
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
