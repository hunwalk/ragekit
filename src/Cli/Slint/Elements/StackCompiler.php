<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;

/** <VStack> / <HStack>: Slint's vertical / horizontal layout, gap 8px unless padded="false". */
final class StackCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return array_keys(self::LAYOUTS);
    }

    private const LAYOUTS = ['VStack' => 'VerticalLayout', 'HStack' => 'HorizontalLayout'];

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $this->context->checkAttributes($element, ['grow', 'padded', 'when', 'each'], styled: true);
        $style = $this->context->styleOf($element, $scope, 'stack');
        $own = $element->tag === 'VStack' ? 'v' : 'h';
        $anyGrows = false;
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->context->element($child, $scope, $own); // first: it resolves the child's styles (flex)
            $anyGrows = $anyGrows || $this->grows($child, $scope) || ($child->namespace === null && $child->tag === 'Content');
        }
        $padded = !isset($element->attributes['padded']) || $this->context->flag($element, 'padded');
        $layout = ['spacing' => $padded ? '8px' : '0px', 'alignment' => $anyGrows ? 'stretch' : 'start'] + [];
        $layout = array_merge($layout, $style->layout);
        $lines = [...CompileContext::styleLines($style, $layout), ...CompileContext::styleLines($style, $style->box), $this->context->stretch($element, $axis)];
        $code = Code::block(self::LAYOUTS[$element->tag === 'WindowDrag' ? 'HStack' : $element->tag], $lines, $children);
        return $this->context->withMargin($code, $style, $element, $axis);
    }

    /** Whether an element takes the leftover space: grow="true", or a flex style above 0. */
    private function grows(Element $element, Scope $scope): bool
    {
        if ($this->context->flag($element, 'grow')) {
            return true;
        }
        $style = $this->context->resolved($element);
        return $style !== null && $style->flex !== null && (float) $style->flex > 0;
    }
}
