<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;

/**
 * One compiled window component: every screen sharing a window class (a Window subclass with a
 * template, or Window itself), each a page shown while rk-page is its index. Binding and action
 * ids are unique across the whole window, so a page's root.pN never meets another page's.
 * Window-level bindings and actions belong to the window ('window': the Window subclass's own
 * properties and handlers, and its toasts) or the screen on top ('context': the window
 * template's {{ $context->x() }}).
 */
final class WindowModel
{
    /** @var array<string, Binding> window-level, by "owner:source:name" */
    public array $bindings = [];

    /** @var list<Action> window-level */
    public array $actions = [];

    /** @var list<ScreenModel> by page index */
    public array $pages = [];

    private int $bindingIds = 0;

    private int $actionIds = 0;

    public function __construct(public readonly string $name, public readonly ?ClassInfo $frame = null)
    {
    }

    /**
     * rk-screens for screens in Window itself, rk-screens-<Window> for a Window subclass's screens
     * (lowercase with a dash: no kit component or PHP class can have that name). $frame is the
     * Window subclass that draws the window (null: the OS's title bar).
     */
    public static function nameFor(?ClassInfo $frame): string
    {
        return $frame === null ? 'rk-screens' : 'rk-screens-' . $frame->short;
    }

    public function nextBindingId(): int
    {
        return ++$this->bindingIds;
    }

    public function nextActionId(): int
    {
        return ++$this->actionIds;
    }

    public function bind(string $source, string $name, string $type, string $owner): Binding
    {
        return $this->bindings["$owner:$source:$name"] ??= new Binding($this->nextBindingId(), $source, $name, $type, false, $owner);
    }

    /**
     * @param list<array{0: string, 1: string}> $args
     * @param list<string>|null $params
     */
    public function action(string $kind, string $target, array $args, ?Binding $binding, string $owner, ?array $params): Action
    {
        $action = new Action($this->nextActionId(), $kind, $target, $args, $binding, $owner, $params);
        $this->actions[] = $action;
        return $action;
    }
}
