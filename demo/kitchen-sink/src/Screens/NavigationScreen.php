<?php

namespace Demo\Screens;

/** Sidebar, nav list, breadcrumb, pagination and accordion. */
final class NavigationScreen extends Page
{
    public const SECTIONS = ['Home', 'Projects', 'Settings'];

    public string $section = 'Projects';
    public int $page = 1;
    public int $open = 0;

    public static function template(): string
    {
        return 'templates/Screens/Navigation.rk';
    }

    /** @return list<string> the breadcrumb: Home, then the current section */
    public function trail(): array
    {
        return $this->section === 'Home' ? ['Home'] : ['Home', $this->section];
    }

    public function goTo(string $crumb): void
    {
        $this->section = $crumb;
    }
}
