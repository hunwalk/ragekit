<?php

namespace Demo;

use RageKit\Screen;

final class HomeScreen extends Screen implements TitleBarContext
{
    public int $searches = 0;
    public string $last = '';

    public static function template(): string
    {
        return 'templates/HomeScreen.rk';
    }

    public static function layout(): string
    {
        return AppLayout::class;
    }

    public function title(): string
    {
        return 'Home';
    }

    public function search(string $query): void
    {
        $this->searches++;
        $this->last = $query;
    }

    public function summary(): string
    {
        return $this->searches === 0 ? 'Nothing searched yet' : "Searches: {$this->searches} (last: \"{$this->last}\")";
    }

    public function openSettings(): void
    {
        $this->app->open(new SettingsScreen(), title: 'Settings', width: 480, height: 300);
    }
}
