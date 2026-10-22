<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\TextAttribute;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\IncompleteKitElement;
use RageKit\Cli\Templates\Errors\MisplacedElement;
use RageKit\Cli\Templates\Errors\MissingKitElement;
use RageKit\Cli\Templates\Errors\UnexpectedChild;
use RageKit\Cli\Templates\TemplateException;

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
        $items = [];
        $variants = [];
        $branches = [];
        foreach ($element->children as $i => $item) {
            if ($item->namespace !== null || $item->tag !== 'MenuItem') {
                throw new UnexpectedChild($item, 'DropdownMenu', 'MenuItem', 'menu items are fixed: no @if or @foreach around them');
            }
            $context->checkAttributes($item, ['text', 'onClick', 'variant']);
            $handler = $item->attributes['onClick'] ?? throw TemplateException::at($item, '<MenuItem> needs onClick="method": what picking it does');
            $variant = $item->attributes['variant'] ?? 'default';
            if (!in_array($variant, ['default', 'destructive'], true)) {
                throw TemplateException::at($item, "<MenuItem variant=\"$variant\">: variant is default or destructive");
            }
            $items[] = $context->values->value($item->attributes['text'] ?? '', 'string', null, $scope, $item, 'text');
            $variants[] = TextAttribute::slintString($variant);
            $branches[] = "if (rk-index == $i) {\n" . Code::indent($context->values->handler($handler, $scope, $item), 1) . "}";
        }
        if ($items === []) {
            throw TemplateException::at($element, '<DropdownMenu> needs at least one <MenuItem>');
        }
        $context->useKit($kit, $spec->component);
        $lines = [];
        foreach (['text' => '', 'variant' => 'outline'] as $name => $default) {
            $prop = $spec->props[$name] ?? throw new IncompleteKitElement($element, $kit, 'DropdownMenu', "$name prop");
            $raw = $element->attributes[$name] ?? $default;
            $lines[] = "{$prop->property}: " . $context->values->value($raw, $prop->type, $prop->enum, $scope, $element, $name) . ';';
        }
        $lines[] = ($spec->props['items']->property ?? 'items') . ': [' . implode(', ', $items) . '];';
        $lines[] = ($spec->props['variants']->property ?? 'variants') . ': [' . implode(', ', $variants) . '];';
        // rk-index: no template variable can have that name (a loop's $index would shadow `index`).
        $lines[] = "$selected(rk-index) => {\n" . Code::indent(implode(' else ', $branches) . "\n", 1) . '}';
        $style = $context->styleOf($element, $scope, 'kit', ['text', 'variant']);
        $lines = [...$lines, ...CompileContext::styleLines($style, $style->box)];
        $lines[] = $context->stretch($element, $axis);
        return $context->withMargin(Code::block($spec->component, $lines), $style, $element, $axis);
    }
}
