<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\StyleResult;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * <View>: React Native's box. A Rectangle (background, border, corners, clip, shadow) holding a
 * FlexboxLayout for its children (column, no wrap by default, like React Native); children with
 * position: absolute sit outside the layout, placed from top/right/bottom/left.
 */
final class ViewCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['View'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $this->context->checkAttributes($element, ['grow', 'when', 'each'], styled: true);
        $style = $this->context->styleOf($element, $scope, 'view');
        $layout = array_merge(['flex-direction' => 'column', 'flex-wrap' => 'no-wrap'], $style->layout);
        $own = str_starts_with($layout['flex-direction'], 'row') ? 'h' : 'v';
        $flow = '';
        $placed = '';
        foreach ($element->children as $child) {
            $leaves = ControlFlowCompiler::leaves($child);
            foreach ($leaves as $leaf) {
                $this->context->markInView($leaf);
            }
            $code = $this->context->element($child, $scope, $own);
            $absolute = array_map(fn(Element $leaf) => $this->context->resolved($leaf)?->position === 'absolute', $leaves);
            if (count(array_unique($absolute)) > 1) {
                throw TemplateException::at($child, 'inside a <View>, the elements of one @if/@foreach must all be absolutely positioned, or none');
            }
            if ($absolute[0] ?? false) {
                // A directive's absolute elements were placed by body(); a plain child is placed here.
                $placed .= $leaves === [$child] ? self::place($code, $this->context->resolved($child)) : $code;
            } else {
                $flow .= $code;
            }
        }
        $lines = [...CompileContext::styleLines($style, $style->view), ...CompileContext::styleLines($style, $style->box)];
        if ($this->context->isRoot($element)) {
            $lines[] = 'width: parent.width;';
            $lines[] = 'height: parent.height;';
        } else {
            $lines[] = $this->context->stretch($element, $axis);
        }
        $box = Code::block('FlexboxLayout', CompileContext::styleLines($style, $layout), $flow);
        return $this->context->withMargin(Code::block('Rectangle', $lines, $box . $placed), $style, $element, $axis);
    }

    /**
     * An absolutely positioned child, inside a one-cell layout placed at x/y: the layout takes the
     * child's natural size from its constraints (forcing the child's own size would override sizes
     * its component sets, like a badge's fixed height).
     */
    public static function place(string $code, StyleResult $style): string
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
        return Code::block('HorizontalLayout', $lines, $code);
    }
}
