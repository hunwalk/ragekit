<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Rageblade\Parser;
use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Ref;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\TextAttribute;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\OneChildRequired;
use RageKit\Cli\Templates\TemplateException;

/** @foreach and @if (rk-foreach / rk-if after Rageblade), and the older each= / when= attributes. */
final class ControlFlowCompiler implements ElementCompiler
{
    /** How many @foreach loops enclose the one being compiled: each depth has its own hidden index. */
    private int $depth = 0;

    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['rk-foreach', 'rk-if'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        return $element->tag === 'rk-foreach' ? $this->foreach($element, $scope, $axis) : $this->ifChain($element, $scope, $axis);
    }

    /**
     * each="item in list" / when="flag" (the older syntax): the element inside a Slint `for` / `if`;
     * null when it has neither. $bare compiles the element itself.
     *
     * @param \Closure(Element, Scope, string): string $bare
     */
    public function attributes(Element $element, Scope $scope, string $axis, \Closure $bare): ?string
    {
        $each = $element->attributes['each'] ?? null;
        $when = $element->attributes['when'] ?? null;
        if ($each !== null || $when !== null) {
            $this->context->warnOnce($element->file, "{$element->file}: each= and when= still work but are replaced by @foreach and @if (see docs/templates.md)");
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
            $source = $this->context->values->resolve($m[2], $scope, $element);
            if ($source->type !== 'list') {
                throw TemplateException::at($element, "each=\"$each\": '{$m[2]}' is not a list");
            }
            $item = new Ref((string) $source->itemType, $m[1], $source->itemType === 'list' ? 'string' : null);
            return "for {$m[1]} in {$source->slint} : " . $this->context->guarded(fn() => $bare($element, $scope->with($m[1], $item), $axis));
        }
        if ($when !== null) {
            $name = ltrim($when, '!');
            if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
                throw TemplateException::at($element, TextAttribute::hasRefs($name) || str_contains($name, '{')
                    ? "when=\"$when\": write the name without braces, e.g. when=\"" . trim($when, '{}') . '"'
                    : "when=\"$when\": expressions aren't supported; name a bool property or a computed method returning bool, optionally with !, e.g. when=\"hasItems\"");
            }
            $condition = $this->context->values->resolve($name, $scope, $element);
            if ($condition->type !== 'bool') {
                throw TemplateException::at($element, "when=\"$when\": '$name' is not a bool");
            }
            return 'if ' . (str_starts_with($when, '!') ? '!' : '') . $condition->slint . ' : ' . $this->context->guarded(fn() => $bare($element, $scope, $axis));
        }
        return null;
    }

    /** @foreach: Slint's `for`, with the item (and index) as variables of the one element inside. */
    private function foreach(Element $element, Scope $scope, string $axis): string
    {
        $raw = $element->attributes['source'] ?? '';
        $source = $this->context->expressions->compile(Parser::parse($raw, $element->file, $element->line), $scope, $element);
        if ($source->type !== 'list' && $source->type !== 'rows') {
            throw TemplateException::at($element, "@foreach needs a list or a collection; $raw is a {$source->type}");
        }
        if (count($element->children) !== 1) {
            throw new OneChildRequired($element, '@foreach');
        }
        $as = $element->attributes['as'] ?? '';
        $index = $element->attributes['index'] ?? null;
        if ($as === 'store' || $index === 'store') {
            throw TemplateException::at($element, "\$store is reserved for the app's stores; name the loop variable something else");
        }
        if ($as === 'assets' || $index === 'assets') {
            throw TemplateException::at($element, "\$assets is reserved for the app's assets; name the loop variable something else");
        }
        // An item of a bound list keeps where the list comes from, and an index (a hidden one when
        // the template names none: rk-i, rk-i2 in a loop inside it, …), so an image can show the
        // same list as images.
        $hidden = 'rk-i' . ($this->depth === 0 ? '' : $this->depth + 1);
        $inner = $scope->with($as, match (true) {
            $source->type === 'rows' => new Ref('row', $as, row: $source->row),
            $source->origin !== null && $source->itemType === 'string' => new Ref('string', $as, origin: $source->origin, index: $index ?? $hidden),
            default => new Ref((string) $source->itemType, $as, $source->itemType === 'list' ? 'string' : null),
        });
        if ($index !== null) {
            $inner = $inner->with($index, new Ref('int', $index));
        }
        $this->depth++;
        try {
            $body = $this->body($element->children[0], $inner, $axis);
        } finally {
            $this->depth--;
        }
        $indexName = $index ?? (str_contains($body, "[$hidden]") ? $hidden : null);
        return "for $as" . ($indexName === null ? '' : "[$indexName]") . " in {$source->slint} : " . $body;
    }

    /** @if / @elseif / @else: one Slint `if` per branch, each guarded by the earlier conditions being false. */
    private function ifChain(Element $element, Scope $scope, string $axis): string
    {
        $out = '';
        $earlier = [];
        foreach ($element->children as $branch) {
            if (count($branch->children) !== 1) {
                throw new OneChildRequired($element, 'each @if branch');
            }
            $test = $branch->attributes['test'] ?? null;
            $condition = $test === null ? null : $this->context->expressions->condition(Parser::parse($test, $branch->file, $branch->line), $scope, $branch)->slint;
            $guard = array_map(fn(string $c) => "!($c)", $earlier);
            if ($condition !== null) {
                $guard[] = $condition;
                $earlier[] = $condition;
            }
            $out .= 'if ' . ($guard === [] ? 'true' : implode(' && ', $guard)) . ' : ' . $this->body($branch->children[0], $scope, $axis);
        }
        return $out;
    }

    /**
     * The one element of a `for`/`if`. Slint wants an element there, so a body that is itself a
     * `for`/`if` (a directive, or each=/when=) gets a zero-gap layout around it; an absolutely
     * positioned body (inside a <View>) is placed like any absolute child.
     */
    private function body(Element $child, Scope $scope, string $axis): string
    {
        $code = $this->context->guarded(fn() => $this->context->element($child, $scope, $axis));
        if (self::isControl($child)) {
            $layout = $axis === 'h' ? 'HorizontalLayout' : 'VerticalLayout';
            return Code::block($layout, ['padding: 0px;', 'spacing: 0px;'], $code);
        }
        $style = $this->context->resolved($child);
        return $style !== null && $style->position === 'absolute' ? ViewCompiler::place($code, $style) : $code;
    }

    /** Whether an element compiles to a Slint `for` or `if`. */
    private static function isControl(Element $element): bool
    {
        return ($element->namespace === null && in_array($element->tag, ['rk-foreach', 'rk-if'], true))
            || isset($element->attributes['each']) || isset($element->attributes['when']);
    }

    /** @return list<Element> the elements a directive finally shows (itself if it isn't one) */
    public static function leaves(Element $element): array
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
}
