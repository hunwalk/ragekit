<?php

namespace Demo;

use RageKit\Screen;

/** A second window in the same layout: its own bar state, its own maximise state. */
final class SettingsScreen extends Screen implements TitleBarContext
{
    public bool $compact = false;

    public static function template(): string
    {
        return 'templates/SettingsScreen.rk';
    }

    public static function layout(): string
    {
        return AppLayout::class;
    }

    public function title(): string
    {
        return 'Settings';
    }

    public function search(string $query): void
    {
    }
}
