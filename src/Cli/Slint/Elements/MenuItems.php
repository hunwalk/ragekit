<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\TextAttribute;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\UnexpectedChild;
use RageKit\Cli\Templates\TemplateException;

/** The <MenuItem>s of a <DropdownMenu> or <ContextMenu>: texts, variants, and what picking each runs. */
final class MenuItems
{
    /**
     * @param list<string> $items    Slint string expressions
     * @param list<string> $variants Slint string literals
     */
    private function __construct(public readonly array $items, public readonly array $variants, private readonly array $branches)
    {
    }

    public static function of(CompileContext $context, Element $menu, Scope $scope): self
    {
        $items = [];
        $variants = [];
        $branches = [];
        foreach ($menu->children as $i => $item) {
            if ($item->namespace !== null || $item->tag !== 'MenuItem') {
                throw new UnexpectedChild($item, $menu->tag, 'MenuItem', 'menu items are fixed: no @if or @foreach around them');
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
            throw TemplateException::at($menu, "<{$menu->tag}> needs at least one <MenuItem>");
        }
        return new self($items, $variants, $branches);
    }

    /**
     * The kit component's lines: its items, their variants, and the select callback.
     * rk-index: no template variable can have that name (a loop's $index would shadow `index`).
     */
    public function lines(string $itemsProperty, string $variantsProperty, string $callback): array
    {
        return [
            "$itemsProperty: [" . implode(', ', $this->items) . '];',
            "$variantsProperty: [" . implode(', ', $this->variants) . '];',
            "$callback(rk-index) => {\n" . Code::indent(implode(' else ', $this->branches) . "\n", 1) . '}',
        ];
    }
}
