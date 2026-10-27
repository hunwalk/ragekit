<?php

namespace RageKit\Tray;

use RageKit\App;
use RageKit\Events;
use RageKit\Modules;

/**
 * The app's icon in the system tray (tray module): a tooltip, a click and a menu. One per app.
 *
 *     $tray = Tray::show('assets/tray.png', 'My app', onClick: fn() => $this->main()->show());
 *     $tray->item('Open', fn() => $this->main()->show())
 *          ->separator()
 *          ->item('Quit', fn() => $this->quit());
 *
 * To keep running with the main window closed, hide it instead (App::mainWindowClosed()). Linux:
 * desktops with a StatusNotifierItem host (KDE, XFCE, GNOME with the AppIndicator extension).
 * Windows: the taskbar's notification area. macOS: not yet.
 */
final class Tray
{
    private static ?Tray $current = null;

    /** @var list<array{0: string, 1: ?\Closure}> label (or "-"), what a click runs */
    private array $items = [];

    private ?\Closure $onClick;

    private function __construct(?\Closure $onClick)
    {
        $this->onClick = $onClick;
        Events::on('tray', 1, 'activate', function (string $data): void {
            if ($this->onClick !== null) {
                ($this->onClick)();
            }
        });
        Events::on('tray', 1, 'menu', function (string $data): void {
            $fn = $this->items[(int) $data][1] ?? null;
            if ($fn !== null) {
                $fn();
            }
        });
    }

    /** Whether a tray is there (the module's library and the desktop's tray host). */
    public static function available(): bool
    {
        return self::why() === '';
    }

    /** Why there's no tray ('' when there is). */
    public static function why(): string
    {
        if (!Modules::available('tray')) {
            return 'the tray module isn\'t available: ' . \ragekit_module_error('tray');
        }
        return \tray_available();
    }

    /**
     * Shows the app's tray icon, or updates it. $icon: a png or svg file (relative paths: the app's
     * folder). $onClick runs when the icon is clicked (the menu opens with the other button).
     *
     * @throws \RuntimeException when it couldn't be shown
     */
    public static function show(string $icon, string $tooltip = '', ?\Closure $onClick = null): Tray
    {
        Modules::require('tray');
        if (\tray_show(App::resolve($icon), $tooltip) === 0) {
            throw new \RuntimeException('the tray icon couldn\'t be shown: ' . \tray_error());
        }
        if (self::$current === null) {
            self::$current = new Tray($onClick);
        } elseif ($onClick !== null) {
            self::$current->onClick = $onClick;
        }
        return self::$current;
    }

    /** Adds a menu item; $fn runs when it's chosen. @throws \InvalidArgumentException for an empty label */
    public function item(string $label, \Closure $fn): Tray
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('a tray menu item needs a label');
        }
        $this->items[] = [str_replace("\n", ' ', $label === '-' ? '–' : $label), $fn];
        \tray_menu($this->labels());
        return $this;
    }

    public function separator(): Tray
    {
        $this->items[] = ['-', null];
        \tray_menu($this->labels());
        return $this;
    }

    /** Removes every menu item. */
    public function clearMenu(): Tray
    {
        $this->items = [];
        \tray_menu('');
        return $this;
    }

    public function tooltip(string $text): Tray
    {
        \tray_tooltip($text);
        return $this;
    }

    /** Takes the icon away. */
    public function remove(): void
    {
        \tray_remove();
        Events::off('tray', 1);
        $this->items = [];
        self::$current = null;
    }

    private function labels(): string
    {
        $labels = [];
        foreach ($this->items as $item) {
            $labels[] = $item[0];
        }
        return implode("\n", $labels);
    }
}
