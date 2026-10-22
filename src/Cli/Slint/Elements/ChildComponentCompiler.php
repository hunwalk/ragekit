<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\StyleProperties;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\NoChildrenAllowed;
use RageKit\Cli\Templates\TemplateException;

/** <Track title="…" />: a use of one of the app's own (child) components, with its props. */
final class ChildComponentCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return array_keys($this->context->children);
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $child = $this->context->children[$element->tag];
        $this->context->checkAttributes($element, [...array_keys($child->props), 'grow', 'when', 'each'], styled: true);
        if ($element->children !== []) {
            throw new NoChildrenAllowed($element);
        }
        $lines = [];
        foreach ($child->props as $name => $type) {
            if (isset($element->attributes[$name])) {
                $propType = $this->context->values->valueType($type, $element, "property \$$name of {$child->short}");
                $lines[] = "rk-$name: " . $this->context->values->value($element->attributes[$name], $propType, null, $scope, $element, $name) . ';';
            }
        }
        if (isset($element->attributes['style']) || isset($element->attributes['rk-style'])) {
            foreach (array_keys($child->props) as $prop) {
                if (in_array($prop, StyleProperties::names(), true)) {
                    throw TemplateException::at($element, "<{$element->tag}>: its prop \$$prop has the name of a style property, so style can't be used on it; rename the prop");
                }
            }
        }
        $style = $this->context->styleOf($element, $scope, 'child', array_keys($child->props));
        $lines = [...$lines, ...CompileContext::styleLines($style, $style->box)];
        $lines[] = $this->context->stretch($element, $axis);
        return $this->context->withMargin(Code::block($child->short, $lines), $style, $element, $axis);
    }
}
