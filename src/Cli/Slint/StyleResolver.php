<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Rageblade\Node;
use RageKit\Cli\Rageblade\Parser;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * Merges an element's styles, React Native's way: named styles from style="a b" and @style([...])
 * (left to right, later wins; 'name' => condition applies only while the condition is true), then
 * inline style attributes (width="{{ … }}"), which win. Styles come from the STYLES constant of the
 * class whose template is being compiled.
 */
final class StyleResolver
{
    private readonly ExpressionCompiler $expressions;

    public function __construct(ValueCompiler $values)
    {
        $this->expressions = new ExpressionCompiler($values);
    }

    /**
     * @param string       $kind     view, label, kit, stack or child: decides which properties are allowed
     * @param list<string> $reserved attributes that belong to the element itself (a kit element's own props)
     */
    public function resolve(Element $element, Scope $scope, string $kind, array $reserved = []): StyleResult
    {
        $maps = ['box' => [], 'view' => [], 'layout' => [], 'text' => [], 'margin' => [], 'meta' => []];
        $transition = null;
        foreach ($this->entries($element, $scope, $reserved) as [$label, $condition, $props, $inline, $describe]) {
            foreach ($props as $property => $value) {
                $where = $label === null ? '' : "style '$label': ";
                $property = (string) $property;
                $spec = $this->spec($property, $element, $where);
                $this->allowed($property, $spec, $kind, $element);
                if ($condition !== null && $spec['scope'] === 'meta') {
                    throw TemplateException::at($element, "@style: '$label' sets $property only when $describe; position, offsets, margin, flex and transition"
                        . " can't depend on a condition (use @if to show a different element)");
                }
                if ($property === 'transition') {
                    $transition = is_numeric($value) ? (int) $value : throw TemplateException::at($element, "{$where}transition takes milliseconds, e.g. 150");
                    continue;
                }
                foreach ($this->convert($property, $spec, $value, $inline, $scope, $element, $where) as $key => $slint) {
                    $bucket = $spec['scope'] === 'meta' ? (str_starts_with($property, 'margin') ? 'margin' : 'meta') : $spec['scope'];
                    if ($condition === null) {
                        $maps[$bucket][$key] = $slint;
                        continue;
                    }
                    $old = $maps[$bucket][$key] ?? StyleProperties::DEFAULTS[$key]
                        ?? throw TemplateException::at($element, "@style: '$label' sets $property only when $describe; give the element "
                            . (in_array($property[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a') . " $property in its base style too");
                    $maps[$bucket][$key] = "($condition ? $slint : $old)";
                }
            }
        }
        $margin = $maps['margin'] === [] ? null : array_map(fn(string $side) => $maps['margin'][$side] ?? '0px', ['top', 'right', 'bottom', 'left']);
        $offsets = array_intersect_key($maps['meta'], array_flip(['top', 'right', 'bottom', 'left']));
        return new StyleResult(
            $maps['box'], $maps['view'], $maps['layout'], $maps['text'], $margin,
            $maps['meta']['flexGrow'] ?? $maps['meta']['flex'] ?? null,
            $maps['meta']['position'] ?? null, $offsets, $transition,
        );
    }

    /**
     * @param list<string> $reserved
     * @return list<array{0: string|null, 1: string|null, 2: array<string, mixed>, 3: bool, 4: string}>
     *         [style name (null: inline), condition (Slint), properties, inline?, condition as written]
     */
    private function entries(Element $element, Scope $scope, array $reserved): array
    {
        $entries = [];
        foreach (preg_split('/\s+/', trim($element->attributes['style'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $name) {
            $entries[] = [$name, null, $this->named($name, $scope, $element), false, ''];
        }
        if (isset($element->attributes['rk-style'])) {
            $list = Parser::parse($element->attributes['rk-style'], $element->file, $element->line);
            if ($list->kind !== 'array') {
                throw TemplateException::at($element, "@style takes a list: @style(['row', 'selected' => \$isSelected])");
            }
            foreach ($list->children as $item) {
                [$first, $second] = $item->children;
                if ($first->kind !== 'string') {
                    throw TemplateException::at($element, "@style entries are style names ('row') or 'name' => condition");
                }
                $name = (string) $first->value;
                $condition = $second === null ? null : $this->expressions->condition($second, $scope, $element)->slint;
                $entries[] = [$name, $condition, $this->named($name, $scope, $element), false, $second === null ? '' : self::describe($second)];
            }
        }
        $inline = [];
        foreach (StyleProperties::names() as $property) {
            if (isset($element->attributes[$property]) && !in_array($property, $reserved, true)) {
                $inline[$property] = $element->attributes[$property];
            }
        }
        if ($inline !== []) {
            $entries[] = [null, null, $inline, true, ''];
        }
        return $entries;
    }

    /** @return array<string, mixed> */
    private function named(string $name, Scope $scope, Element $element): array
    {
        $styles = $scope->class->constants['STYLES'] ?? [];
        if (!is_array($styles) || !isset($styles[$name]) || !is_array($styles[$name])) {
            throw TemplateException::at($element, "style '$name' isn't in {$scope->class->short}::STYLES");
        }
        return $styles[$name];
    }

    /** @return array{scope: string, type: string, slint?: string|list<string>, enum?: array<string, string>} */
    private function spec(string $property, Element $element, string $where): array
    {
        if (isset(StyleProperties::UNSUPPORTED[$property])) {
            throw TemplateException::at($element, "{$where}$property isn't supported: " . StyleProperties::UNSUPPORTED[$property]);
        }
        if (!isset(StyleProperties::ALL[$property])) {
            $best = null;
            $distance = 4;
            foreach (StyleProperties::names() as $known) {
                $d = levenshtein(strtolower($property), strtolower($known));
                if ($d < $distance) {
                    [$best, $distance] = [$known, $d];
                }
            }
            throw TemplateException::at($element, "{$where}unknown property $property" . ($best === null ? '' : " (did you mean $best?)"));
        }
        return StyleProperties::ALL[$property];
    }

    private function allowed(string $property, array $spec, string $kind, Element $element): void
    {
        $scope = $spec['scope'];
        $tag = "<{$element->tag}>";
        if ($scope === 'box' || $scope === 'meta' || $kind === 'view') {
            return;
        }
        if ($kind === 'label' && $scope === 'text') {
            return;
        }
        if ($kind === 'stack' && in_array($property, StyleProperties::STACK_LAYOUT, true)) {
            return;
        }
        throw TemplateException::at($element, match ($kind) {
            'stack' => match ($scope) {
                'view' => "$tag can't take $property: use a <View> for backgrounds, borders and corners",
                'text' => "$tag can't take $property: text styles go on a <Label> or <Text>",
                default => "$tag can't take $property: use a <View> for flexbox layout",
            },
            'child' => "$tag can't take $property: a component's look comes from its own template; style the elements inside it",
            default => "$tag can't take $property: kit elements only accept size, margin, flex, position and opacity styles"
                . ($kind === 'label' ? ' (and Label/Text text styles)' : '')
                . '. To change how a kit draws it, copy the kit into your app with `ragekit eject <kit>`.',
        });
    }

    /** @return array<string, string> Slint key => Slint value (a property may set several keys) */
    private function convert(string $property, array $spec, mixed $value, bool $inline, Scope $scope, Element $element, string $where): array
    {
        if ($inline && is_string($value) && str_contains($value, '{{')) {
            return $this->keys($property, $spec, $this->dynamic($property, $spec, $value, $scope, $element));
        }
        if ($inline && is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }
        $slint = match ($spec['type']) {
            'size' => is_string($value) && preg_match('/^\d+(\.\d+)?%$/', $value) === 1 ? $value : $this->length($property, $value, $element, $where),
            'length' => $this->length($property, $value, $element, $where),
            'number' => is_int($value) || is_float($value) ? self::number($value) : throw TemplateException::at($element, "{$where}$property takes a number"),
            'color' => $this->color($property, $value, $element, $where),
            'weight' => $this->weight($property, $value, $element, $where),
            'enum' => $this->enum($property, $spec, $value, $element, $where),
            'offset' => $this->offset($property, $value, $element, $where),
            default => (string) $value,
        };
        return is_array($slint) ? array_combine($spec['slint'], $slint) : $this->keys($property, $spec, $slint);
    }

    /** @return array<string, string> */
    private function keys(string $property, array $spec, string $slint): array
    {
        $keys = $spec['slint'] ?? $property;
        return array_fill_keys(is_array($keys) ? $keys : [$keys], $slint);
    }

    private function dynamic(string $property, array $spec, string $raw, Scope $scope, Element $element): string
    {
        if ($spec['type'] === 'color') {
            throw TemplateException::at($element, "$property: Slint can't turn text into a colour at runtime; switch colours with @style");
        }
        if (!in_array($spec['type'], ['size', 'length', 'number'], true)) {
            throw TemplateException::at($element, "$property takes a fixed value, not an expression");
        }
        if (preg_match('/^\s*\{\{(.*)\}\}\s*$/s', $raw, $m) !== 1) {
            throw TemplateException::at($element, "$property=\"$raw\": write one {{ expression }} and no other text");
        }
        $ref = $this->expressions->compile(Parser::parse($m[1], $element->file, $element->line), $scope, $element);
        if (!in_array($ref->type, ['int', 'float'], true)) {
            throw TemplateException::at($element, "$property needs a number, the expression is " . ($ref->type === 'int' ? 'an' : 'a') . " {$ref->type}");
        }
        return $spec['type'] === 'number' ? $ref->slint : "({$ref->slint}) * 1px";
    }

    private function length(string $property, mixed $value, Element $element, string $where): string
    {
        if (!is_int($value) && !is_float($value)) {
            throw TemplateException::at($element, "{$where}$property takes a number of pixels" . ($property === 'width' || $property === 'height' ? " or a percentage like '50%'" : ''));
        }
        return self::number($value) . 'px';
    }

    private function color(string $property, mixed $value, Element $element, string $where): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === 'transparent'
            || preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $text) === 1
            || preg_match('/^rgba?\(\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*){2,3}\)$/', $text) === 1) {
            return $text;
        }
        throw TemplateException::at($element, "{$where}$property '" . (is_scalar($value) ? $value : gettype($value)) . "' isn't a colour; use #rgb, #rrggbb, #rrggbbaa, rgb(), rgba() or transparent");
    }

    private function weight(string $property, mixed $value, Element $element, string $where): string
    {
        $named = ['normal' => 400, 'bold' => 700];
        $weight = is_string($value) && isset($named[$value]) ? $named[$value] : (is_numeric($value) ? (int) $value : 0);
        if ($weight < 100 || $weight > 900) {
            throw TemplateException::at($element, "{$where}$property takes 100 to 900, 'normal' or 'bold'");
        }
        return (string) $weight;
    }

    private function enum(string $property, array $spec, mixed $value, Element $element, string $where): string
    {
        if ($property === 'alignItems' && $value === 'baseline') {
            throw TemplateException::at($element, "{$where}alignItems 'baseline' isn't supported (use flex-start, flex-end, center or stretch)");
        }
        if (!is_string($value) || !isset($spec['enum'][$value])) {
            throw TemplateException::at($element, "{$where}$property must be one of: " . implode(', ', array_keys($spec['enum'])));
        }
        return $spec['enum'][$value];
    }

    /** @return list<string> */
    private function offset(string $property, mixed $value, Element $element, string $where): array
    {
        if (!is_array($value) || !is_numeric($value['width'] ?? null) || !is_numeric($value['height'] ?? null)) {
            throw TemplateException::at($element, "{$where}$property takes ['width' => x, 'height' => y]");
        }
        return [self::number($value['width'] + 0) . 'px', self::number($value['height'] + 0) . 'px'];
    }

    private static function number(int|float $value): string
    {
        return is_int($value) || floor($value) !== $value ? (string) $value : (string) (int) $value;
    }

    private static function describe(Node $node): string
    {
        return match ($node->kind) {
            'var' => '$' . $node->value,
            'call' => $node->value . '()',
            'not' => '!' . self::describe($node->children[0]),
            default => 'its condition holds',
        };
    }
}
