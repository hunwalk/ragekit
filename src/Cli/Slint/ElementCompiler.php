<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Rageblade\Parser;
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
    /** Elements every kit provides with the same attributes (docs/kits.md, "The core element contract"). */
    public const CORE = [
        'Label', 'Button', 'Entry', 'Checkbox', 'Slider', 'Select', 'Image', 'Progress', 'Card', 'Separator',
        'Switch', 'Textarea', 'RadioGroup', 'Toggle', 'Badge', 'Avatar', 'Alert', 'Skeleton', 'Spinner',
        'Sidebar', 'NavList', 'Breadcrumb', 'Pagination', 'Text', 'Dialog', 'DropdownMenu',
    ];
    private const LAYOUTS = ['VStack' => 'VerticalLayout', 'HStack' => 'HorizontalLayout'];

    /** @var array<string, array<string, true>> kit entry file => Slint components used from it */
    private array $imports = [];

    /** @var array<string, string> Slint libraries the used kits need (name => path) */
    private array $libraries = [];

    /** @var array<string, string> template file => deprecation hint, once per file */
    private array $warnings = [];

    private readonly ExpressionCompiler $expressions;

    private readonly StyleResolver $styles;

    /** @var array<int, StyleResult> resolved styles by element (spl_object_id) */
    private array $resolved = [];

    /** @var array<int, true> elements whose parent is a <View>, where position: absolute works */
    private array $inView = [];

    /** @var array<int, true> root <View>s of a screen: they fill the window */
    private array $roots = [];

    /** How many for/if (directives, each=, when=) the element being compiled is inside. */
    private int $guarded = 0;

    /** @var list<string> dialogs of the screen being compiled, for the window's top level (takeOverlays) */
    private array $overlays = [];

    /** @param array<string, ClassInfo> $children short name => child component */
    public function __construct(
        private readonly KitRegistry $kits,
        private readonly string $activeKit,
        private readonly array $children,
        private readonly ValueCompiler $values,
    ) {
        $this->expressions = new ExpressionCompiler($values);
        $this->styles = new StyleResolver($values);
    }

    /** @param list<string> $reserved the element's own attributes that look like style properties */
    private function styleOf(Element $element, Scope $scope, string $kind, array $reserved = []): StyleResult
    {
        $id = spl_object_id($element);
        if (!isset($this->resolved[$id])) {
            $style = $this->styles->resolve($element, $scope, $kind, $reserved);
            if ($style->position === 'absolute' && !isset($this->inView[$id])) {
                throw TemplateException::at($element, 'position: absolute needs a <View> parent');
            }
            $this->resolved[$id] = $style;
        }
        return $this->resolved[$id];
    }

    /** The screen's root element: a root <View> fills the window. */
    public function markRoot(Element $element): void
    {
        $this->roots[spl_object_id($element)] = true;
    }

    /**
     * <View>: React Native's box. A Rectangle (background, border, corners, clip, shadow) holding a
     * FlexboxLayout for its children (column, no wrap by default, like React Native); children with
     * position: absolute sit outside the layout, placed from top/right/bottom/left.
     */
    private function view(Element $element, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, ['grow', 'when', 'each'], styled: true);
        $style = $this->styleOf($element, $scope, 'view');
        $layout = array_merge(['flex-direction' => 'column', 'flex-wrap' => 'no-wrap'], $style->layout);
        $own = str_starts_with($layout['flex-direction'], 'row') ? 'h' : 'v';
        $flow = '';
        $placed = '';
        foreach ($element->children as $child) {
            $leaves = self::leaves($child);
            foreach ($leaves as $leaf) {
                $this->inView[spl_object_id($leaf)] = true;
            }
            $code = $this->element($child, $scope, $own);
            $absolute = array_map(fn(Element $leaf) => ($this->resolved[spl_object_id($leaf)] ?? null)?->position === 'absolute', $leaves);
            if (count(array_unique($absolute)) > 1) {
                throw TemplateException::at($child, 'inside a <View>, the elements of one @if/@foreach must all be absolutely positioned, or none');
            }
            if ($absolute[0] ?? false) {
                // A directive's absolute elements were placed by body(); a plain child is placed here.
                $placed .= $leaves === [$child] ? self::place($code, $this->resolved[spl_object_id($child)]) : $code;
            } else {
                $flow .= $code;
            }
        }
        $lines = [...self::styleLines($style, $style->view), ...self::styleLines($style, $style->box)];
        if (isset($this->roots[spl_object_id($element)])) {
            $lines[] = 'width: parent.width;';
            $lines[] = 'height: parent.height;';
        } else {
            $lines[] = $this->stretch($element, $axis);
        }
        $box = "FlexboxLayout {\n" . Code::indent(implode("\n", self::styleLines($style, $layout)) . "\n" . $flow, 1) . "}\n";
        return $this->withMargin("Rectangle {\n" . Code::indent(implode("\n", $lines) . "\n" . $box . $placed, 1) . "}\n", $style, $element, $axis);
    }

    /**
     * An absolutely positioned child, inside a one-cell layout placed at x/y: the layout takes the
     * child's natural size from its constraints (forcing the child's own size would override sizes
     * its component sets, like a badge's fixed height).
     */
    private static function place(string $code, StyleResult $style): string
    {
        $o = $style->offsets;
        $lines = [];
        if (isset($o['left'])) {
            $lines[] = "x: {$o['left']};";
        } elseif (isset($o['right'])) {
            $lines[] = "x: parent.width - self.width - {$o['right']};";
        }
        if (isset($o['top'])) {
            $lines[] = "y: {$o['top']};";
        } elseif (isset($o['bottom'])) {
            $lines[] = "y: parent.height - self.height - {$o['bottom']};";
        }
        $lines = [...$lines, 'width: self.preferred-width;', 'height: self.preferred-height;', 'padding: 0px;'];
        return "HorizontalLayout {\n" . Code::indent(implode("\n", $lines) . "\n" . $code, 1) . "}\n";
    }

    /** @param list<string> $keys style map keys to emit as properties; @return list<string> Slint lines */
    private static function styleLines(StyleResult $style, array $map): array
    {
        $lines = [];
        foreach ($map as $property => $value) {
            $lines[] = "$property: $value;";
        }
        return [...$lines, ...$style->animations(array_keys($map))];
    }

    /** margin: the element inside a padded single-cell layout (Slint has padding, not margin). */
    private function withMargin(string $code, StyleResult $style, Element $element, string $axis): string
    {
        if ($style->margin === null) {
            return $code;
        }
        [$top, $right, $bottom, $left] = $style->margin;
        return "HorizontalLayout {\n    padding-top: $top;\n    padding-right: $right;\n    padding-bottom: $bottom;\n    padding-left: $left;\n"
            . '    ' . $this->stretch($element, $axis) . "\n" . Code::indent($code, 1) . "}\n";
    }

    /** @return list<string> hints for the author (e.g. old syntax), one per template */
    public function warnings(): array
    {
        return array_values($this->warnings);
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
        if ($each !== null || $when !== null) {
            $this->warnings[$element->file] ??= "{$element->file}: each= and when= still work but are replaced by @foreach and @if (see docs/templates.md)";
        }
        if ($element->namespace === null && $element->tag === 'Content' && ($each !== null || $when !== null)) {
            throw TemplateException::at($element, "<Content /> can't have each= or when=; the screen is always shown");
        }
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
            return "for {$m[1]} in {$source->slint} : " . $this->guarded(fn() => $this->bare($element, $scope->with($m[1], $item), $axis));
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
            return 'if ' . (str_starts_with($when, '!') ? '!' : '') . $condition->slint . ' : ' . $this->guarded(fn() => $this->bare($element, $scope, $axis));
        }
        return $this->bare($element, $scope, $axis);
    }

    private function bare(Element $element, Scope $scope, string $axis): string
    {
        if ($element->namespace === null && $element->tag === 'rk-foreach') {
            return $this->foreach($element, $scope, $axis);
        }
        if ($element->namespace === null && $element->tag === 'rk-if') {
            return $this->ifChain($element, $scope, $axis);
        }
        if ($element->namespace === null && $element->tag === 'View') {
            return $this->view($element, $scope, $axis);
        }
        if ($element->namespace === null && in_array($element->tag, ['Content', 'WindowDrag', 'WindowControls'], true)) {
            if (!$scope->inLayout() || $scope->hasItems() && $element->tag === 'Content') {
                $name = $element->tag === 'Content' ? '<Content />' : "<{$element->tag}>";
                throw TemplateException::at($element, $scope->inLayout()
                    ? "$name can't be repeated with each="
                    : "$name is only allowed in a Layout's template");
            }
            return match ($element->tag) {
                'Content' => $this->content($element, $scope, $axis),
                'WindowDrag' => $this->windowDrag($element, $scope, $axis),
                default => $this->windowControls($element, $axis),
            };
        }
        if ($element->namespace === null && $element->tag === 'Tabs') {
            return $this->tabs($element, $scope, $axis);
        }
        if ($element->namespace === null && $element->tag === 'Tab') {
            throw TemplateException::at($element, '<Tab> is only allowed directly inside <Tabs>');
        }
        if ($element->namespace === null && $element->tag === 'Dialog') {
            return $this->dialog($element, $scope);
        }
        if ($element->namespace === null && $element->tag === 'DropdownMenu') {
            return $this->dropdownMenu($element, $scope, $axis);
        }
        if ($element->namespace === null && $element->tag === 'MenuItem') {
            throw TemplateException::at($element, '<MenuItem> is only allowed directly inside <DropdownMenu>');
        }
        if ($element->namespace === null && $element->tag === 'Accordion') {
            return $this->accordion($element, $scope, $axis);
        }
        if ($element->namespace === null && $element->tag === 'Section') {
            throw TemplateException::at($element, '<Section> is only allowed directly inside <Accordion>');
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
        throw TemplateException::at($element, "unknown element <{$element->tag}> (core: View, VStack, HStack, " . implode(', ', self::CORE)
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
        $action = $scope->model?->action('set', $binding->name, [], $binding, $binding->owner);
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
        $this->checkAttributes($element, ['grow', 'padded', 'when', 'each'], styled: true);
        $style = $this->styleOf($element, $scope, 'stack');
        $own = $element->tag === 'VStack' ? 'v' : 'h';
        $anyGrows = false;
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->element($child, $scope, $own); // first: it resolves the child's styles (flex)
            $anyGrows = $anyGrows || $this->grows($child, $scope) || ($child->namespace === null && $child->tag === 'Content');
        }
        $padded = !isset($element->attributes['padded']) || $this->flag($element, 'padded');
        $layout = ['spacing' => $padded ? '8px' : '0px', 'alignment' => $anyGrows ? 'stretch' : 'start'] + [];
        $layout = array_merge($layout, $style->layout);
        $lines = [...self::styleLines($style, $layout), ...self::styleLines($style, $style->box), $this->stretch($element, $axis)];
        $code = self::LAYOUTS[$element->tag === 'WindowDrag' ? 'HStack' : $element->tag] . " {\n"
            . Code::indent(implode("\n", $lines) . "\n" . $children, 1) . "}\n";
        return $this->withMargin($code, $style, $element, $axis);
    }

    /** Whether an element takes the leftover space: grow="true", or a flex style above 0. */
    private function grows(Element $element, Scope $scope): bool
    {
        if ($this->flag($element, 'grow')) {
            return true;
        }
        $style = $this->resolved[spl_object_id($element)] ?? null;
        return $style !== null && $style->flex !== null && (float) $style->flex > 0;
    }

    /** @foreach: Slint's `for`, with the item (and index) as variables of the one element inside. */
    private function foreach(Element $element, Scope $scope, string $axis): string
    {
        $raw = $element->attributes['source'] ?? '';
        $source = $this->expressions->compile(Parser::parse($raw, $element->file, $element->line), $scope, $element);
        if ($source->type !== 'list') {
            throw TemplateException::at($element, "@foreach needs a list; $raw is a {$source->type}");
        }
        if (count($element->children) !== 1) {
            throw TemplateException::at($element, '@foreach must contain one element; wrap several in a <View> or <VStack>');
        }
        $as = $element->attributes['as'] ?? '';
        $index = $element->attributes['index'] ?? null;
        $inner = $scope->with($as, new Ref((string) $source->itemType, $as, $source->itemType === 'list' ? 'string' : null));
        if ($index !== null) {
            $inner = $inner->with($index, new Ref('int', $index));
        }
        return "for $as" . ($index === null ? '' : "[$index]") . " in {$source->slint} : " . $this->body($element->children[0], $inner, $axis);
    }

    /**
     * The one element of a `for`/`if`. Slint wants an element there, so a body that is itself a
     * `for`/`if` (a directive, or each=/when=) gets a zero-gap layout around it; an absolutely
     * positioned body (inside a <View>) is placed like any absolute child.
     */
    private function body(Element $child, Scope $scope, string $axis): string
    {
        $code = $this->guarded(fn() => $this->element($child, $scope, $axis));
        if (self::isControl($child)) {
            $layout = $axis === 'h' ? 'HorizontalLayout' : 'VerticalLayout';
            return "$layout {\n    padding: 0px;\n    spacing: 0px;\n" . Code::indent($code, 1) . "}\n";
        }
        $style = $this->resolved[spl_object_id($child)] ?? null;
        return $style !== null && $style->position === 'absolute' ? self::place($code, $style) : $code;
    }

    /** @param \Closure(): string $compile */
    private function guarded(\Closure $compile): string
    {
        $this->guarded++;
        try {
            return $compile();
        } finally {
            $this->guarded--;
        }
    }

    /** Whether an element compiles to a Slint `for` or `if`. */
    private static function isControl(Element $element): bool
    {
        return ($element->namespace === null && in_array($element->tag, ['rk-foreach', 'rk-if'], true))
            || isset($element->attributes['each']) || isset($element->attributes['when']);
    }

    /** @return list<Element> the elements a directive finally shows (itself if it isn't one) */
    private static function leaves(Element $element): array
    {
        if ($element->namespace === null && $element->tag === 'rk-foreach') {
            return array_merge(...array_map(self::leaves(...), $element->children));
        }
        if ($element->namespace === null && $element->tag === 'rk-if') {
            $out = [];
            foreach ($element->children as $branch) {
                foreach ($branch->children as $child) {
                    $out = [...$out, ...self::leaves($child)];
                }
            }
            return $out;
        }
        return [$element];
    }

    /** @if / @elseif / @else: one Slint `if` per branch, each guarded by the earlier conditions being false. */
    private function ifChain(Element $element, Scope $scope, string $axis): string
    {
        $out = '';
        $earlier = [];
        foreach ($element->children as $branch) {
            if (count($branch->children) !== 1) {
                throw TemplateException::at($element, 'each @if branch must contain one element; wrap several in a <View> or <VStack>');
            }
            $test = $branch->attributes['test'] ?? null;
            $condition = $test === null ? null : $this->expressions->condition(Parser::parse($test, $branch->file, $branch->line), $scope, $branch)->slint;
            $guard = array_map(fn(string $c) => "!($c)", $earlier);
            if ($condition !== null) {
                $guard[] = $condition;
                $earlier[] = $condition;
            }
            $out .= 'if ' . ($guard === [] ? 'true' : implode(' && ', $guard)) . ' : ' . $this->body($branch->children[0], $scope, $axis);
        }
        return $out;
    }

    /** onSelect="goTo": an event whose callback passes a value (the clicked item) to the handler. */
    private function valueEvent(Element $element, string $attribute, string $callback, string $type, Scope $scope): string
    {
        $method = trim($element->attributes[$attribute]);
        if (preg_match('/^[A-Za-z_]\w*$/', $method) !== 1) {
            throw TemplateException::at($element, "$attribute passes the selected item to the method: write $attribute=\"" . preg_replace('/\W.*$/s', '', ltrim($method, '$')) . '"');
        }
        $params = $scope->class->handlers[$method]
            ?? throw TemplateException::at($element, "'$method' is not a public method of {$scope->class->short}");
        if (count($params) !== 1) {
            throw TemplateException::at($element, "$method() must take exactly one parameter: the selected item");
        }
        $param = ltrim($params[0], '?');
        if ($param !== $type && !($param === 'float' && $type === 'int') && !($param === 'string' && $type === 'int')) {
            throw TemplateException::at($element, "$attribute gives " . ($type === 'int' ? 'an' : 'a') . " $type; $method()'s parameter is " . ($param === 'int' ? 'an' : 'a') . " $param");
        }
        $action = $scope->model?->action('call', $method, [['arg', 'value']], null, $scope->owner, $params)
            ?? throw TemplateException::at($element, 'child components can\'t handle events yet; put interactive elements in the screen\'s template');
        return "$callback(value) => { root.action({$action->id}, " . ($type === 'string' ? 'value' : '"\\{value}"') . '); }';
    }

    /**
     * <Accordion open="{{ $open }}"><Section title="…">one element</Section>…</Accordion>: the kit's
     * AccordionHeader per section; one section open at a time (open = its index, -1 = none). Like
     * <Tabs>, closed sections are removed and their state is kept in PHP.
     */
    private function accordion(Element $element, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, ['open', 'grow', 'when', 'each']);
        [$kit, $spec] = $this->coreSpec('AccordionHeader') ?? throw TemplateException::at($element, 'no installed kit provides AccordionHeader');
        $raw = $element->attributes['open'] ?? '';
        $binding = $this->values->twoWayBinding($raw, new \RageKit\Cli\Templates\PropSpec('int', 'open', null, 'changed'), $scope, $element, 'open');
        if ($binding === null || $binding->type !== 'int') {
            throw TemplateException::at($element, "<Accordion open=\"$raw\">: open must bind an int property, e.g. open=\"{{ \$open }}\" (-1: all closed)");
        }
        $action = $scope->model?->action('set', $binding->name, [], $binding, $binding->owner);
        $this->useKit($kit, $spec->component);
        $title = $spec->props['title']->property ?? 'title';
        $open = $spec->props['open']->property ?? 'open';
        $toggled = $spec->events['onToggle'] ?? throw TemplateException::at($element, "kit {$kit->name}: AccordionHeader has no onToggle event");
        $p = 'root.' . $binding->slintName();
        $out = '';
        foreach ($element->children as $i => $section) {
            if ($section->namespace !== null || $section->tag !== 'Section') {
                throw TemplateException::at($section, '<Accordion> may only contain <Section> elements');
            }
            $this->checkAttributes($section, ['title']);
            if (count($section->children) !== 1) {
                throw TemplateException::at($section, '<Section> must contain exactly one element (wrap several in a <VStack>)');
            }
            $out .= $spec->component . " {\n"
                . "    $title: " . Expression::slintString($section->attributes['title'] ?? '') . ";\n"
                . "    $open: $p == $i;\n"
                . "    $toggled => {\n        $p = $p == $i ? -1 : $i;\n        root.action({$action?->id}, \"\\{" . $p . "}\");\n    }\n}\n"
                . "if $p == $i : VerticalLayout {\n    padding-left: 12px;\n    padding-right: 12px;\n    padding-bottom: 8px;\n"
                . Code::indent($this->element($section->children[0], $scope, 'v'), 1) . "}\n";
        }
        return "VerticalLayout {\n    spacing: 0px;\n    alignment: start;\n    " . $this->stretch($element, $axis) . "\n"
            . Code::indent($out, 1) . "}\n";
    }

    /**
     * <Dialog open="{{ $confirm }}" title="…">…</Dialog>: the kit's Dialog (a scrim over the whole
     * window with a centred panel) shown while the two-way bool `open` is true. It's placed at the
     * window's top level, wherever it's written, so it covers everything (also a layout's title
     * bar); an outside click or Esc sets `open` back to false. Leaves nothing where it's written.
     */
    private function dialog(Element $element, Scope $scope): string
    {
        $this->checkAttributes($element, ['open', 'title']);
        if ($scope->model === null) {
            throw TemplateException::at($element, "<Dialog> can't be in a child component's template; put it in the screen's (or layout's) template");
        }
        if ($this->guarded > 0) {
            throw TemplateException::at($element, "<Dialog> can't be inside @if, @foreach, each= or when=; use open=\"{{ \$prop }}\" to show it");
        }
        [$kit, $spec] = $this->coreSpec('Dialog') ?? throw TemplateException::at($element, 'no installed kit provides Dialog');
        $raw = $element->attributes['open'] ?? throw TemplateException::at($element, '<Dialog> needs open="{{ $prop }}", a bool property that shows it');
        $binding = $this->values->twoWayBinding($raw, new \RageKit\Cli\Templates\PropSpec('bool', 'open', null, 'changed'), $scope, $element, 'open');
        if ($binding === null || $binding->type !== 'bool') {
            throw TemplateException::at($element, "<Dialog open=\"$raw\">: open must bind a bool property, e.g. open=\"{{ \$confirmDelete }}\"");
        }
        $action = $scope->model->action('set', $binding->name, [], $binding, $binding->owner);
        $dismissed = $spec->events['onDismiss'] ?? throw TemplateException::at($element, "kit {$kit->name}: Dialog has no onDismiss event");
        $this->useKit($kit, $spec->component);
        $p = 'root.' . $binding->slintName();
        $lines = ['x: 0;', 'y: 0;', 'width: root.width;', 'height: root.height;'];
        if (isset($element->attributes['title'])) {
            $title = $spec->props['title']->property ?? 'title';
            $lines[] = "$title: " . $this->values->value($element->attributes['title'], 'string', null, $scope, $element, 'title') . ';';
        }
        $lines[] = "$dismissed => { $p = false; root.action({$action->id}, \"0\"); }";
        // Its slot comes first, so a dialog inside it (compiled below) is drawn on top of it.
        $slot = count($this->overlays);
        $this->overlays[] = '';
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->element($child, $scope, 'v');
        }
        $this->overlays[$slot] = "if $p : {$spec->component} {\n" . Code::indent(implode("\n", $lines) . "\n" . $children, 1) . "}\n";
        return '';
    }

    /**
     * <DropdownMenu text="⋯"><MenuItem text="Rename" onClick="rename" />…</DropdownMenu>: the kit's
     * trigger button and popup list; picking an item runs that item's handler. It opens and closes
     * by itself, so there's no property behind it.
     */
    private function dropdownMenu(Element $element, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, ['text', 'variant', 'grow', 'when', 'each'], styled: true);
        [$kit, $spec] = $this->coreSpec('DropdownMenu') ?? throw TemplateException::at($element, 'no installed kit provides DropdownMenu');
        $selected = $spec->events['onSelect'] ?? throw TemplateException::at($element, "kit {$kit->name}: DropdownMenu has no onSelect event");
        $items = [];
        $variants = [];
        $branches = [];
        foreach ($element->children as $i => $item) {
            if ($item->namespace !== null || $item->tag !== 'MenuItem') {
                throw TemplateException::at($item, '<DropdownMenu> may only contain <MenuItem> elements (menu items are fixed: no @if or @foreach around them)');
            }
            $this->checkAttributes($item, ['text', 'onClick', 'variant']);
            $handler = $item->attributes['onClick'] ?? throw TemplateException::at($item, '<MenuItem> needs onClick="method": what picking it does');
            $variant = $item->attributes['variant'] ?? 'default';
            if (!in_array($variant, ['default', 'destructive'], true)) {
                throw TemplateException::at($item, "<MenuItem variant=\"$variant\">: variant is default or destructive");
            }
            $items[] = $this->values->value($item->attributes['text'] ?? '', 'string', null, $scope, $item, 'text');
            $variants[] = Expression::slintString($variant);
            $branches[] = "if (rk-index == $i) {\n" . Code::indent($this->values->handler($handler, $scope, $item), 1) . "}";
        }
        if ($items === []) {
            throw TemplateException::at($element, '<DropdownMenu> needs at least one <MenuItem>');
        }
        $this->useKit($kit, $spec->component);
        $lines = [];
        foreach (['text' => '', 'variant' => 'outline'] as $name => $default) {
            $prop = $spec->props[$name] ?? throw TemplateException::at($element, "kit {$kit->name}: DropdownMenu has no $name prop");
            $raw = $element->attributes[$name] ?? $default;
            $lines[] = "{$prop->property}: " . $this->values->value($raw, $prop->type, $prop->enum, $scope, $element, $name) . ';';
        }
        $lines[] = ($spec->props['items']->property ?? 'items') . ': [' . implode(', ', $items) . '];';
        $lines[] = ($spec->props['variants']->property ?? 'variants') . ': [' . implode(', ', $variants) . '];';
        // rk-index: no template variable can have that name (a loop's $index would shadow `index`).
        $lines[] = "$selected(rk-index) => {\n" . Code::indent(implode(' else ', $branches) . "\n", 1) . '}';
        $style = $this->styleOf($element, $scope, 'kit', ['text', 'variant']);
        $lines = [...$lines, ...self::styleLines($style, $style->box)];
        $lines[] = $this->stretch($element, $axis);
        return $this->withMargin($spec->component . " {\n" . Code::indent(implode("\n", $lines) . "\n", 1) . "}\n", $style, $element, $axis);
    }

    /** @return string the dialogs collected since the last call, for the window's top level */
    public function takeOverlays(): string
    {
        $overlays = implode('', $this->overlays);
        $this->overlays = [];
        return $overlays;
    }

    /** A layout's <Content />: the screen's template, padded as a window without a layout would be. */
    private function content(Element $element, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, []);
        if ($element->children !== []) {
            throw TemplateException::at($element, "<Content /> can't contain other elements");
        }
        $root = $scope->screenRoot;
        $screen = $scope->screen;
        if ($root === null || $screen === null) {
            throw TemplateException::at($element, "<Content /> is only allowed in a Layout's template");
        }
        $body = $this->element($root, $screen, 'v');
        return "VerticalLayout {\n    padding: 16px;\n    alignment: " . ($this->flag($root, 'grow') ? 'stretch' : 'start') . ";\n"
            . '    ' . ($axis === 'h' ? 'horizontal' : 'vertical') . "-stretch: 1;\n"
            . Code::indent($body, 1) . "}\n";
    }

    /** <WindowDrag>: an <HStack> whose background moves the window (a double-click, found natively, maximises). */
    private function windowDrag(Element $element, Scope $scope, string $axis): string
    {
        $stack = $this->layout($element, $scope, $axis);
        return "TouchArea {\n"
            . "    pointer-event(event) => {\n"
            . "        if (event.kind == PointerEventKind.down && event.button == PointerEventButton.left) {\n"
            . "            root.rk-window(\"move-start\");\n"
            . "        }\n"
            . "    }\n"
            . "    moved => {\n        if (self.pressed) {\n            root.rk-window(\"move\");\n        }\n    }\n"
            . '    ' . $this->stretch($element, $axis) . "\n"
            . Code::indent($stack, 1) . "}\n";
    }

    /** <WindowControls />: the kit's minimise / maximise / close buttons, handled natively. */
    private function windowControls(Element $element, string $axis): string
    {
        $this->checkAttributes($element, ['grow', 'when']);
        if ($element->children !== []) {
            throw TemplateException::at($element, "<WindowControls /> can't contain other elements");
        }
        [$kit, $spec] = $this->coreSpec('WindowControls') ?? throw TemplateException::at($element, 'no installed kit provides WindowControls');
        $this->useKit($kit, $spec->component);
        $lines = [];
        if (isset($spec->props['maximized'])) {
            $lines[] = "{$spec->props['maximized']->property}: root.rk-maximized;";
        }
        foreach (['onMinimize' => 'minimize', 'onToggleMaximize' => 'toggle-maximize', 'onClose' => 'close'] as $event => $command) {
            $callback = $spec->events[$event] ?? throw TemplateException::at($element, "kit {$kit->name}: WindowControls has no $event event");
            $lines[] = "$callback => { root.rk-window(\"$command\"); }";
        }
        $lines[] = $this->stretch($element, $axis);
        return $spec->component . " {\n" . Code::indent(implode("\n", $lines), 1) . "}\n";
    }

    private function kitElement(Element $element, Kit $kit, ElementSpec $spec, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, [...array_keys($spec->props), ...array_keys($spec->events), 'grow', 'when', 'each'], styled: true);
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
                $action = $scope->model?->action('set', $binding->name, [], $binding, $binding->owner);
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
            if (!isset($element->attributes[$attribute])) {
                continue;
            }
            $lines[] = isset($spec->eventValues[$attribute])
                ? $this->valueEvent($element, $attribute, $callback, $spec->eventValues[$attribute], $scope)
                : "$callback => { " . $this->values->handler($element->attributes[$attribute], $scope, $element) . ' }';
        }
        $style = $this->styleOf($element, $scope, $element->namespace === null && in_array($element->tag, ['Label', 'Text'], true) ? 'label' : 'kit', array_keys($spec->props));
        $lines = [...$lines, ...self::styleLines($style, $style->box), ...self::styleLines($style, $style->text)];
        $lines[] = $this->stretch($element, $axis);
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->element($child, $scope, 'v');
        }
        return $this->withMargin($spec->component . " {\n" . Code::indent(implode("\n", $lines) . "\n" . $children, 1) . "}\n", $style, $element, $axis);
    }

    private function childUsage(Element $element, ClassInfo $child, Scope $scope, string $axis): string
    {
        $this->checkAttributes($element, [...array_keys($child->props), 'grow', 'when', 'each'], styled: true);
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
        if (isset($element->attributes['style']) || isset($element->attributes['rk-style'])) {
            foreach (array_keys($child->props) as $prop) {
                if (in_array($prop, StyleProperties::names(), true)) {
                    throw TemplateException::at($element, "<{$element->tag}>: its prop \$$prop has the name of a style property, so style can't be used on it; rename the prop");
                }
            }
        }
        $style = $this->styleOf($element, $scope, 'child', array_keys($child->props));
        $lines = [...$lines, ...self::styleLines($style, $style->box)];
        $lines[] = $this->stretch($element, $axis);
        return $this->withMargin($child->short . " {\n" . Code::indent(implode("\n", $lines), 1) . "}\n", $style, $element, $axis);
    }

    private function stretch(Element $element, string $axis): string
    {
        $flex = $this->resolved[spl_object_id($element)]->flex ?? null;
        return ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: ' . ($flex ?? ($this->flag($element, 'grow') ? '1' : '0')) . ';';
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
    /** @param bool $styled whether the element applies style attributes (style, @style, width="…") */
    private function checkAttributes(Element $element, array $allowed, bool $styled = false): void
    {
        $styling = $styled ? ['style', 'rk-style', ...StyleProperties::names()] : [];
        foreach (array_keys($element->attributes) as $name) {
            if (!in_array($name, $allowed, true) && !in_array($name, $styling, true)) {
                throw TemplateException::at($element, "<{$element->tag}> has no attribute '$name' (allowed: " . implode(', ', $allowed) . ')');
            }
        }
    }
}
