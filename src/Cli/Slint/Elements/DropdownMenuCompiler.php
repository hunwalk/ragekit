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
 * <DropdownMenu text="⋯"><MenuItem text="Rename" onClick="rename" />…</DropdownMenu>: the kit's
 * trigger button and popup list; picking an item runs that item's handler. It opens and closes
 * by itself, so there's no property behind it. A <MenuItem> anywhere else is an error.
 */
final class DropdownMenuCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['DropdownMenu', 'MenuItem'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        if ($element->tag === 'MenuItem') {
            throw new MisplacedElement($element, 'DropdownMenu');
        }
        $context = $this->context;
        $context->checkAttributes($element, ['text', 'variant', 'grow', 'when', 'each'], styled: true);
        [$kit, $spec] = $context->coreSpec('DropdownMenu') ?? throw new MissingKitElement($element, 'DropdownMenu');
        $selected = $spec->events['onSelect'] ?? throw new IncompleteKitElement($element, $kit, 'DropdownMenu', 'onSelect event');
        $menu = MenuItems::of($context, $element, $scope);
        $context->useKit($kit, $spec->component);
        $lines = [];
        foreach (['text' => '', 'variant' => 'outline'] as $name => $default) {
            $prop = $spec->props[$name] ?? throw new IncompleteKitElement($element, $kit, 'DropdownMenu', "$name prop");
            $raw = $element->attributes[$name] ?? $default;
            $lines[] = "{$prop->property}: " . $context->values->value($raw, $prop->type, $prop->enum, $scope, $element, $name) . ';';
        }
        $lines = [...$lines, ...$menu->lines($spec->props['items']->property ?? 'items', $spec->props['variants']->property ?? 'variants', $selected)];
        $style = $context->styleOf($element, $scope, 'kit', ['text', 'variant']);
        $lines = [...$lines, ...CompileContext::styleLines($style, $style->box)];
        $lines[] = $context->stretch($element, $axis);
        return $context->withMargin(Code::block($spec->component, $lines), $style, $element, $axis);
    }
}
