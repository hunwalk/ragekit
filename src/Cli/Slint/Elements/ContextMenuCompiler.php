<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\IncompleteKitElement;
use RageKit\Cli\Templates\Errors\MisplacedElement;
use RageKit\Cli\Templates\Errors\MissingKitElement;

/**
 * <View><ContextMenu><MenuItem text="Reply" onClick="reply($m->id)" />…</ContextMenu></View>: a
 * right-click on the View opens the kit's menu at the pointer (ViewCompiler calls menu() and wires
 * the click); picking an item runs its handler. Anywhere but directly in a <View> it's an error.
 */
final class ContextMenuCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['ContextMenu'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        throw new MisplacedElement($element, 'View');
    }

    /** The kit's menu as element $id (open it with "$id.open(x, y)"). */
    public function menu(Element $element, Scope $scope, string $id): string
    {
        $context = $this->context;
        $context->checkAttributes($element, []);
        [$kit, $spec] = $context->coreSpec('ContextMenu') ?? throw new MissingKitElement($element, 'ContextMenu');
        $selected = $spec->events['onSelect'] ?? throw new IncompleteKitElement($element, $kit, 'ContextMenu', 'onSelect event');
        $menu = MenuItems::of($context, $element, $scope);
        $context->useKit($kit, $spec->component);
        // At the View's corner: open(x, y) takes the pointer's position within the View (an element
        // without x/y would be centred, and the menu with it).
        $lines = ['x: 0px;', 'y: 0px;', ...$menu->lines($spec->props['items']->property ?? 'items', $spec->props['variants']->property ?? 'variants', $selected)];
        return Code::block("$id := {$spec->component}", $lines);
    }
}
