<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;

/**
 * <ScrollView>: its children in a column that scrolls vertically (wheel, drag, its scrollbar), for
 * lists longer than the window, chats included:
 *   stickToBottom   opens at the bottom, and stays there while rows come in or the window resizes
 *                   as long as the user is at the bottom (scrolled up, the view stays put)
 *   anchor="bottom" keeps the distance from the bottom whatever changes: set it while prepending
 *                   older rows, so what's shown doesn't jump ("top", the default, keeps the top)
 *   onTop           a handler called when the view comes near the top (load older rows), and
 *                   again when rows arrive while it's still there and the rows don't fill the
 *                   view yet, or with anchor="bottom" (older rows came in): a short list fills up
 * A Flickable around a VerticalLayout, with a thin scrollbar of its own (kit-neutral grey).
 */
final class ScrollViewCompiler implements ElementCompiler
{
    /** How close to the top counts as "near the top" for onTop. */
    private const THRESHOLD = '200px';

    /** Ids within one compile (the dispatcher, and so this compiler, is made per compile). */
    private int $next = 0;

    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['ScrollView'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $this->context->checkAttributes($element, ['grow', 'padded', 'stickToBottom', 'anchor', 'onTop', 'when', 'each'], styled: true);
        $style = $this->context->styleOf($element, $scope, 'stack');
        $values = $this->context->values;
        $stick = isset($element->attributes['stickToBottom'])
            ? $values->value($element->attributes['stickToBottom'], 'bool', null, $scope, $element, 'stickToBottom')
            : 'false';
        $anchor = $element->attributes['anchor'] ?? 'top';
        $anchorBottom = str_contains($anchor, '{')
            ? '(' . $values->value($anchor, 'string', null, $scope, $element, 'anchor') . ' == "bottom")'
            : ($values->value($anchor, 'string', ['top', 'bottom'], $scope, $element, 'anchor') === '"bottom"' ? 'true' : 'false');
        $onTop = isset($element->attributes['onTop']) ? $values->handler($element->attributes['onTop'], $scope, $element) : '';
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->context->element($child, $scope, 'v');
        }
        $padded = !isset($element->attributes['padded']) || $this->context->flag($element, 'padded');
        $lines = [...CompileContext::styleLines($style, $style->box), ...CompileContext::styleLines($style, $style->layout)];
        // It takes the room it's given (a list's natural height is all of it); grow="false" opts out.
        $lines[] = isset($element->attributes['grow']) || $style->flex !== null ? $this->context->stretch($element, $axis)
            : ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: 1;';
        $code = Code::stub('scroll-view', [
            'id' => 'rk-scroll-' . ++$this->next,
            'lines' => implode("\n", $lines),
            'spacing' => $padded ? '8px' : '0px',
            'alignment' => $stick === 'false' ? 'start' : 'end',
            'children' => $children,
            'stick' => $stick,
            'anchorBottom' => $anchorBottom,
            'threshold' => self::THRESHOLD,
            'onTop' => $onTop,
        ]);
        return $this->context->withMargin($code, $style, $element, $axis);
    }
}
