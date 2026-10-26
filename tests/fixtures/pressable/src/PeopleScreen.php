<?php

namespace Fix;

use RageKit\Screen;

/** Rows to press, hover and right-click, and two shortcuts; every event prints a line and shows it. */
final class PeopleScreen extends Screen
{
    public const STYLES = [
        'row' => ['flexDirection' => 'row', 'alignItems' => 'center', 'justifyContent' => 'space-between', 'padding' => 8, 'borderRadius' => 8, 'backgroundColor' => '#18181b'],
    ];

    public People $people;

    public string $status = 'ready';

    public string $query = '';

    public function __construct()
    {
        $this->people = new People([new Person('ann'), new Person('bob')]);
    }

    public static function template(): string
    {
        return 'templates/PeopleScreen.rk';
    }

    public function open(string $name): void
    {
        $this->event("open $name");
    }

    public function wave(string $name): void
    {
        $this->event("wave $name");
    }

    public function hoverIn(string $name): void
    {
        $this->event("hover in $name");
    }

    public function hoverOut(string $name): void
    {
        $this->event("hover out $name");
    }

    public function reply(string $name): void
    {
        $this->event("reply $name");
    }

    public function remove(string $name): void
    {
        $this->event("remove $name");
    }

    /** Ctrl+F: the search box takes the keyboard focus. */
    public function find(): void
    {
        $this->focus('search');
        $this->event('find');
    }

    public function submit(): void
    {
        $this->event('submit ' . $this->query);
    }

    public function escape(): void
    {
        $this->event('escape');
    }

    private function event(string $line): void
    {
        $this->status = $line;
        echo "$line\n";
    }
}
