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
 *
 * Like React Native's Pressable it takes onPress, onHoverIn and onHoverOut, and a <ContextMenu>
 * child opens on a right-click. Then a TouchArea holds the layout: elements inside that handle
 * clicks themselves (buttons) still get them, and the pointer over them still counts as over the View.
 */
final class ViewCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context, private readonly ContextMenuCompiler $menus)
    {
    }

    public function tags(): array
    {
        return ['View'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $this->context->checkAttributes($element, ['grow', 'when', 'each', 'onPress', 'onHoverIn', 'onHoverOut'], styled: true);
        $style = $this->context->styleOf($element, $scope, 'view');
        $layout = array_merge(['flex-direction' => 'column', 'flex-wrap' => 'no-wrap'], $style->layout);
        $own = str_starts_with($layout['flex-direction'], 'row') ? 'h' : 'v';
        // Decided before the children: an @if of text in a plain column needs no flexbox workaround.
        $column = $this->columnLines($layout);
        [$flow, $placed, $menu] = $this->context->inView($column !== null, fn() => $this->children($element, $scope, $own));
        $lines = [...CompileContext::styleLines($style, $style->view), ...CompileContext::styleLines($style, $style->box)];
        if ($this->context->isRoot($element)) {
            $lines[] = 'width: parent.width;';
            $lines[] = 'height: parent.height;';
        } else {
            $lines[] = $this->context->stretch($element, $axis);
            // Slint's wrapping FlexboxLayout reports a maximum width of about one child, which
            // capped the whole window there (min of its children's maximums); a View without a width
            // of its own may grow like any block (React Native's default).
            if ($layout['flex-wrap'] !== 'no-wrap' && preg_match('/^(max-)?width:/m', implode("\n", $lines)) !== 1) {
                $lines[] = 'max-width: 100000px;';
            }
        }
        $box = $this->repeatedColumn($element, $style, $column, $flow)
            ?? Code::block('FlexboxLayout', CompileContext::styleLines($style, $layout), $flow);
        $box = $this->pressable($element, $scope, $box, $menu);
        return $this->context->withMargin(Code::block('Rectangle', $lines, $box . $placed), $style, $element, $axis);
    }

    /** @return array{0: string, 1: string, 2: ?Element} the laid-out children, the absolutely placed ones, the ContextMenu */
    private function children(Element $element, Scope $scope, string $own): array
    {
        $flow = '';
        $placed = '';
        $menu = null;
        foreach ($element->children as $child) {
            if ($child->namespace === null && $child->tag === 'ContextMenu') {
                if ($menu !== null) {
                    throw TemplateException::at($child, 'a <View> has one <ContextMenu>');
                }
                $menu = $child;
                continue;
            }
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
        return [$flow, $placed, $menu];
    }

    /**
     * A plain column View that a @foreach repeats, as a VerticalLayout: slint-compiler 1.18 overflows
     * its stack on a column FlexboxLayout repeated inside another one with wrapping text in it (a
     * list of cards). A column without wrapping, cross-axis alignment or alignSelf children lays out
     * the same way. Null: keep the FlexboxLayout.
     *
     * @param array<string, string> $layout
     */
    /**
     * The VerticalLayout lines for a plain column View that a @foreach repeats, or null (a flexbox):
     * no wrapping, no cross-axis alignment, no column gaps.
     *
     * @param array<string, string> $layout
     * @return array<string, string>|null
     */
    private function columnLines(array $layout): ?array
    {
        if (!$this->context->inLoop() || $layout['flex-direction'] !== 'column' || $layout['flex-wrap'] !== 'no-wrap') {
            return null;
        }
        $lines = [];
        foreach ($layout as $property => $value) {
            if ($property === 'flex-direction' || $property === 'flex-wrap') {
                continue;
            }
            if ($property === 'cross-axis-alignment' && $value === 'stretch') {
                continue; // what a VerticalLayout does anyway
            }
            if (!in_array($property, ['alignment', 'spacing', 'spacing-vertical', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left'], true)) {
                return null; // cross-axis alignment, column gaps: flexbox only
            }
            $lines[$property === 'spacing-vertical' ? 'spacing' : $property] = $value;
        }
        $lines['alignment'] ??= 'start'; // a flexbox's start, not a VerticalLayout's stretch
        return $lines;
    }

    /** @param array<string, string>|null $lines from columnLines() */
    private function repeatedColumn(Element $element, StyleResult $style, ?array $lines, string $flow): ?string
    {
        if ($lines === null) {
            return null;
        }
        foreach ($element->children as $child) {
            foreach (ControlFlowCompiler::leaves($child) as $leaf) {
                if (isset($this->context->resolved($leaf)?->box['cross-axis-self-alignment'])) {
                    return null;
                }
            }
        }
        return Code::block('VerticalLayout', CompileContext::styleLines($style, $lines), $flow);
    }

    /** The layout inside a TouchArea when the View has events or a context menu; else as it is. */
    private function pressable(Element $element, Scope $scope, string $box, ?Element $menu): string
    {
        $events = array_intersect_key($element->attributes, array_flip(['onPress', 'onHoverIn', 'onHoverOut']));
        if ($events === [] && $menu === null) {
            return $box;
        }
        $values = $this->context->values;
        $lines = ['width: 100%;', 'height: 100%;'];
        if (isset($events['onPress'])) {
            $lines[] = 'mouse-cursor: pointer;';
            $lines[] = "clicked => {\n" . Code::indent($values->handler($events['onPress'], $scope, $element), 1) . '}';
        }
        if (isset($events['onHoverIn']) || isset($events['onHoverOut'])) {
            $in = isset($events['onHoverIn']) ? $values->handler($events['onHoverIn'], $scope, $element) : '';
            $out = isset($events['onHoverOut']) ? $values->handler($events['onHoverOut'], $scope, $element) : '';
            $lines[] = "changed has-hover => {\n    if (self.has-hover) {\n" . Code::indent($in, 2) . "    } else {\n" . Code::indent($out, 2) . "    }\n}";
        }
        $children = $box;
        if ($menu !== null) {
            $id = $this->context->nextId('rk-menu');
            $lines[] = "pointer-event(event) => {\n    if (event.button == PointerEventButton.right && event.kind == PointerEventKind.down) {\n        $id.open(self.mouse-x, self.mouse-y);\n    }\n}";
            $children .= $this->menus->menu($menu, $scope, $id);
        }
        return Code::block('TouchArea', $lines, $children);
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
