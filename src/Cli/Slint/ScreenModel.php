<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;

/**
 * One screen of a window component (a page): its own bindings and actions (owner 'screen'). Other
 * owners (window, context, store) are the window's (WindowModel), shared by all its pages.
 */
final class ScreenModel
{
    /** @var array<string, Binding> by "owner:source:name" */
    public array $bindings = [];

    /** @var list<Action> */
    public array $actions = [];

    public readonly WindowModel $window;

    /** This screen's page in its window: rk-page shows it while it has this value. */
    public readonly int $page;

    /**
     * Adds itself as the next page of $window (by default a window of its own).
     *
     * @param ClassInfo|null $frame the Window subclass drawing this screen's window (null: Window itself)
     */
    public function __construct(public readonly ClassInfo $class, public readonly ?ClassInfo $frame = null, ?WindowModel $window = null)
    {
        $this->window = $window ?? new WindowModel(WindowModel::nameFor($frame), $frame);
        $this->page = count($this->window->pages);
        $this->window->pages[] = $this;
    }

    public function bind(string $source, string $name, string $type, string $owner = 'screen', ?RowType $row = null): Binding
    {
        if ($owner !== 'screen') {
            return $this->window->bind($source, $name, $type, $owner, $row);
        }
        $key = "$owner:$source:$name" . (in_array($type, ['image', 'images'], true) ? ":$type" : '');
        return $this->bindings[$key] ??= new Binding($this->window->nextBindingId(), $source, $name, $type, false, $owner, $row);
    }

    /**
     * The window's actions (owners window, context) are window-level; a store action is the
     * window's only when written in the window's template ($inWindowTemplate), else it's this
     * page's, so a click sent from a page that has gone does nothing.
     *
     * @param list<array{0: 'literal'|'arg', 1: string}> $args
     * @param list<string>|null $params
     */
    public function action(string $kind, string $target, array $args, ?Binding $binding = null, string $owner = 'screen', ?array $params = null, bool $inWindowTemplate = false): Action
    {
        if ($owner === 'window' || $owner === 'context' || ($owner === 'store' && $inWindowTemplate)) {
            return $this->window->action($kind, $target, $args, $binding, $owner, $params);
        }
        $action = new Action($this->window->nextActionId(), $kind, $target, $args, $binding, $owner, $params);
        $this->actions[] = $action;
        return $action;
    }
}
