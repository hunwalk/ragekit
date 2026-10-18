<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\ElementSpec;
use RageKit\Cli\Templates\Kit;
use RageKit\Cli\Templates\KitRegistry;
use RageKit\Cli\Templates\TemplateException;

/**
 * Turns template elements into Slint: layouts (VStack/HStack), core and kit elements, Tabs, and
 * uses of child components. Records which kit components the result imports.
 */
final class ElementCompiler
{
    private const CORE = ['Label', 'Button', 'Entry', 'Checkbox', 'Slider', 'Select', 'Image', 'Progress', 'Card', 'Separator'];
    private const LAYOUTS = ['VStack' => 'VerticalLayout', 'HStack' => 'HorizontalLayout'];

    /** @var array<string, array<string, true>> kit entry file => Slint components used from it */
    private array $imports = [];

    /** @var array<string, string> Slint libraries the used kits need (name => path) */
    private array $libraries = [];

    /** @param array<string, ClassInfo> $children short name => child component */
    public function __construct(
        private readonly KitRegistry $kits,
        private readonly string $activeKit,
        private readonly array $children,
        private readonly ValueCompiler $values,
    ) {
    }

    /** @return array<string, array<string, true>> */
    public function imports(): array
    {
        return $this->imports;
    }

    /** @return array<string, string> */
    public function libraries(): array
    {
        return $this->libraries;
    }

    public function useKit(Kit $kit, string $component): void
    {
        $this->imports[$kit->entry][$component] = true;
        $this->libraries += $kit->libraries;
    }

    public function element(Element $element, Scope $scope, string $axis): string
    {
        $each = $element->attributes['each'] ?? null;
        $when = $element->attributes['when'] ?? null;
        if ($each !== null && $when !== null) {
            throw TemplateException::at($element, 'an element can\'t have both each="…" and when="…"; wrap it in a <VStack>');
        }
        if ($each !== null) {
            if (preg_match('/^\s*([A-Za-z_]\w*)\s+in\s+([A-Za-z_]\w*)\s*$/', $each, $m) !== 1) {
                throw TemplateException::at($element, "each=\"$each\" must look like each=\"item in list\", where list is a list property or computed method (no braces, no expressions)");
            }
            $source = $this->values->resolve($m[2], $scope, $element);
            if ($source->type !== 'list') {
                throw TemplateException::at($element, "each=\"$each\": '{$m[2]}' is not a list");
            }
            $item = new Ref((string) $source->itemType, $m[1], $source->itemType === 'list' ? 'string' : null);
            return "for {$m[1]} in {$source->slint} : " . $this->bare($element, $scope->with($m[1], $item), $axis);
        }
        if ($when !== null) {
            $name = ltrim($when, '!');
            if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
                throw TemplateException::at($element, Expression::hasRefs($name) || str_contains($name, '{')
                    ? "when=\"$when\": write the name without braces, e.g. when=\"" . trim($when, '{}') . '"'
                    : "when=\"$when\": expressions aren't supported; name a bool property or a computed method returning bool, optionally with !, e.g. when=\"hasItems\"");
            }
            $condition = $this->values->resolve($name, $scope, $element);
            if ($condition->type !== 'bool') {
                throw TemplateException::at($element, "when=\"$when\": '$name' is not a bool");
            }
            return 'if ' . (str_starts_with($when, '!') ? '!' : '') . $condition->slint . ' : ' . $this->bare($element, $scope, $axis);
        }
        return $this->bare($element, $scope, $axis);
    }

    private function bare(Element $element, Scope $scope, string $axis): string
    {
        if ($element->namespace === null && $element->tag === 'Tabs') {
            return $this->tabs($element, $scope, $axis);
        }
        if ($element->namespace === null && $element->tag === 'Tab') {
            throw TemplateException::at($element, '<Tab> is only allowed directly inside <Tabs>');
        }
        if ($element->namespace === null && isset(self::LAYOUTS[$element->tag])) {
            return $this->layout($element, $scope, $axis);
        }
        if ($element->namespace === null && isset($this->children[$element->tag])) {
            return $this->childUsage($element, $this->children[$element->tag], $scope, $axis);
        }
        [$kit, $spec] = $this->elementSpec($element);
        return $this->kitElement($element, $kit, $spec, $scope, $axis);
    }

    /** @return array{0: Kit, 1: ElementSpec} */
    private function elementSpec(Element $element): array
    {
        if ($element->namespace !== null) {
            $kit = $this->kits->find($element->namespace)
                ?? throw TemplateException::at($element, "xmlns \"{$element->namespace}\" is not an installed kit (installed: " . implode(', ', $this->kits->names()) . ')');
            $spec = $kit->elements[$element->tag]
                ?? throw TemplateException::at($element, "kit {$kit->name} has no element <{$element->tag}>");
            return [$kit, $spec];
        }
        if (in_array($element->tag, self::CORE, true)) {
            $found = $this->coreSpec($element->tag);
            if ($found !== null) {
                return $found;
            }
        }
        throw TemplateException::at($element, "unknown element <{$element->tag}> (core: VStack, HStack, " . implode(', ', self::CORE)
            . '; components: ' . (implode(', ', array_keys($this->children)) ?: 'none') . '; kit elements need their prefix, e.g. <ui:Card>)');
    }

    /** @return array{0: Kit, 1: ElementSpec}|null a core element from the active kit, else Material */
    private function coreSpec(string $tag): ?array
    {
        foreach ([$this->activeKit, 'material'] as $name) {
            $kit = $this->kits->get($name);
            if (isset($kit->elements[$tag])) {
                return [$kit, $kit->elements[$tag]];
            }
        }
        return null;
    }

    private function tabs(Element $element, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, ['selected', 'grow', 'when', 'each']);
        [$kit, $spec] = $this->coreSpec('TabBar') ?? throw TemplateException::at($element, 'no installed kit provides TabBar');
        $selected = $spec->props['selected'] ?? throw TemplateException::at($element, "kit {$kit->name}: TabBar has no selected prop");
        $titlesProp = $spec->props['titles'] ?? throw TemplateException::at($element, "kit {$kit->name}: TabBar has no titles prop");
        $raw = $element->attributes['selected'] ?? '';
        $binding = $this->values->twoWayBinding($raw, $selected, $scope, $element, 'selected')
            ?? throw TemplateException::at($element, "<Tabs selected=\"$raw\">: selected must bind an int property, e.g. selected=\"{tab}\"");
        $titles = [];
        foreach ($element->children as $tab) {
            if ($tab->namespace !== null || $tab->tag !== 'Tab') {
                throw TemplateException::at($tab, '<Tabs> may only contain <Tab> elements');
            }
            $this->checkAttributes($tab, ['title']);
            if (Expression::hasRefs($tab->attributes['title'] ?? '')) {
                throw TemplateException::at($tab, "title=\"{$tab->attributes['title']}\": tab titles are fixed text; bindings aren't supported there yet");
            }
            if (count($tab->children) !== 1) {
                throw TemplateException::at($tab, '<Tab> must contain exactly one element (wrap several in a <VStack>)');
            }
            $titles[] = Expression::slintString($tab->attributes['title'] ?? '');
        }
        $this->useKit($kit, $spec->component);
        $action = $scope->model?->action('set', $binding->name, [], $binding);
        $bar = $spec->component . " {\n"
            . "    {$titlesProp->property}: [" . implode(', ', $titles) . "];\n"
            . "    {$selected->property} <=> root.{$binding->slintName()};\n"
            . "    {$selected->twoWay}(value) => { root.action({$action?->id}, \"\\{value}\"); }\n"
            . "}\n";
        $pages = '';
        foreach ($element->children as $i => $tab) {
            $pages .= "if root.{$binding->slintName()} == $i : VerticalLayout {\n    padding: 0px;\n"
                . Code::indent($this->element($tab->children[0], $scope, 'v'), 1) . "}\n";
        }
        return "VerticalLayout {\n    spacing: 12px;\n    alignment: start;\n    " . $this->stretch($element, $axis) . "\n"
            . Code::indent($bar . $pages, 1) . "}\n";
    }

    private function layout(Element $element, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, ['grow', 'padded', 'when', 'each']);
        $own = $element->tag === 'VStack' ? 'v' : 'h';
        $anyGrows = false;
        $children = '';
        foreach ($element->children as $child) {
            $anyGrows = $anyGrows || $this->flag($child, 'grow');
            $children .= $this->element($child, $scope, $own);
        }
        $padded = !isset($element->attributes['padded']) || $this->flag($element, 'padded');
        return self::LAYOUTS[$element->tag] . " {\n"
            . '    spacing: ' . ($padded ? '8px' : '0px') . ";\n"
            . '    alignment: ' . ($anyGrows ? 'stretch' : 'start') . ";\n"
            . '    ' . $this->stretch($element, $axis) . "\n"
            . Code::indent($children, 1) . "}\n";
    }

    private function kitElement(Element $element, Kit $kit, ElementSpec $spec, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, [...array_keys($spec->props), ...array_keys($spec->events), 'grow', 'when', 'each']);
        if ($element->children !== [] && !$spec->children) {
            throw TemplateException::at($element, "<{$element->tag}> can't contain other elements");
        }
        $this->useKit($kit, $spec->component);
        $lines = [];
        foreach ($spec->props as $name => $prop) {
            if (!isset($element->attributes[$name])) {
                continue;
            }
            $raw = $element->attributes[$name];
            $binding = $prop->twoWay !== null ? $this->values->twoWayBinding($raw, $prop, $scope, $element, $name) : null;
            if ($binding !== null) {
                $action = $scope->model?->action('set', $binding->name, [], $binding);
                $value = match ($prop->type) {
                    'bool' => 'value ? "1" : "0"',
                    'string' => 'value',
                    default => '"\{value}"',
                };
                $lines[] = "{$prop->property} <=> root.{$binding->slintName()};";
                $lines[] = "{$prop->twoWay}(value) => { root.action({$action?->id}, $value); }";
                continue;
            }
            $lines[] = "{$prop->property}: " . $this->values->value($raw, $prop->type, $prop->enum, $scope, $element, $name) . ';';
        }
        foreach ($spec->events as $attribute => $callback) {
            if (isset($element->attributes[$attribute])) {
                $lines[] = "$callback => { " . $this->values->handler($element->attributes[$attribute], $scope, $element) . ' }';
            }
        }
        $lines[] = $this->stretch($element, $axis);
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->element($child, $scope, 'v');
        }
        return $spec->component . " {\n" . Code::indent(implode("\n", $lines) . "\n" . $children, 1) . "}\n";
    }

    private function childUsage(Element $element, ClassInfo $child, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, [...array_keys($child->props), 'grow', 'when', 'each']);
        if ($element->children !== []) {
            throw TemplateException::at($element, "<{$element->tag}> can't contain other elements");
        }
        $lines = [];
        foreach ($child->props as $name => $type) {
            if (isset($element->attributes[$name])) {
                $propType = $this->values->valueType($type, $element, "property \$$name of {$child->short}");
                $lines[] = "rk-$name: " . $this->values->value($element->attributes[$name], $propType, null, $scope, $element, $name) . ';';
            }
        }
        $lines[] = $this->stretch($element, $axis);
        return $child->short . " {\n" . Code::indent(implode("\n", $lines), 1) . "}\n";
    }

    private function stretch(Element $element, string $axis): string
    {
        return ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: ' . ($this->flag($element, 'grow') ? '1' : '0') . ';';
    }

    public function flag(Element $element, string $name): bool
    {
        $value = $element->attributes[$name] ?? 'false';
        if ($value !== 'true' && $value !== 'false') {
            throw TemplateException::at($element, "$name=\"$value\" must be \"true\" or \"false\"");
        }
        return $value === 'true';
    }

    /** @param list<string> $allowed */
    private function checkAttributes(Element $element, array $allowed): void
    {
        foreach (array_keys($element->attributes) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw TemplateException::at($element, "<{$element->tag}> has no attribute '$name' (allowed: " . implode(', ', $allowed) . ')');
            }
        }
    }
}
