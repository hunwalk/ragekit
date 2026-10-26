<?php

namespace Demo\Screens;

/** Buttons and every input, in two tabs. */
final class InputsScreen extends Page
{
    public const FRUITS = ['Apple', 'Banana', 'Cherry', 'Dragon fruit'];
    public const SIZES = ['Small', 'Medium', 'Large'];

    public int $tab = 0;
    public string $status = 'Click a button';
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
        return 'templates/Screens/Inputs.rk';
    }

    public function press(string $variant): void
    {
        $this->status = 'Clicked the ' . $variant . ' button';
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

    public function preferences(): string
    {
        return $this->size . ' · ' . ($this->bold ? 'bold' : 'regular') . ' · notifications ' . ($this->notifications ? 'on' : 'off')
            . ' · ' . strlen($this->notes) . ' characters of notes';
    }
}
