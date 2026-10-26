<?php

namespace Demo;

use Demo\Screens\ChatScreen;
use Demo\Screens\DesktopScreen;
use Demo\Screens\DisplayScreen;
use Demo\Screens\InputsScreen;
use Demo\Screens\LayoutScreen;
use Demo\Screens\NavigationScreen;
use Demo\Screens\OverlaysScreen;
use RageKit\Screen;
use RageKit\Window;

/**
 * The window's own title bar and a sidebar of sections (pressable Views); each section is a screen,
 * swapped in place by the navigator. A screen keeps its state when you come back to it.
 */
final class KitchenSinkWindow extends Window
{
    public const SECTIONS = ['Inputs', 'Display', 'Navigation', 'Layout', 'Overlays', 'Chat', 'Desktop'];

    public const STYLES = [
        'sidebar' => ['width' => 168, 'padding' => 8, 'gap' => 2, 'backgroundColor' => 'rgba(127, 127, 127, 0.05)'],
        'item' => ['flexDirection' => 'row', 'alignItems' => 'center', 'justifyContent' => 'space-between',
            'height' => 36, 'paddingHorizontal' => 12, 'borderRadius' => 8, 'transition' => 120],
        'active' => ['backgroundColor' => 'rgba(127, 127, 127, 0.18)'],
        'hovered' => ['backgroundColor' => 'rgba(127, 127, 127, 0.09)'],
    ];

    /** The section shown, and the one under the pointer. */
    public string $current = 'Inputs';

    public string $hovered = '';

    /** @var array<string, Screen> sections visited before, kept with their state */
    private array $visited = [];

    public static function template(): string
    {
        return 'templates/KitchenSinkWindow.rk';
    }

    public function go(string $section): void
    {
        if ($section === $this->current) {
            return;
        }
        $this->visited[$this->current] = $this->navigator()->top();
        $this->current = $section;
        $this->navigator()->replace($this->visited[$section] ?? self::screen($section));
    }

    public function hover(string $section): void
    {
        $this->hovered = $section;
    }

    public function unhover(): void
    {
        $this->hovered = '';
    }

    private static function screen(string $section): Screen
    {
        return match ($section) {
            'Display' => new DisplayScreen(),
            'Navigation' => new NavigationScreen(),
            'Layout' => new LayoutScreen(),
            'Overlays' => new OverlaysScreen(),
            'Chat' => new ChatScreen(),
            'Desktop' => new DesktopScreen(),
            default => new InputsScreen(),
        };
    }
}
