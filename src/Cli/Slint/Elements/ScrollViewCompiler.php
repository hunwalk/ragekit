<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

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

    /**
     * virtual="true": only the rows in view exist (Slint's ListView), for lists of thousands. Its one
     * child must be a @foreach. Rows added above keep what's shown in place by themselves (anchor
     * isn't needed); a short list starts at the top.
     *
     * @param list<string> $lines
     */
    private function virtual(Element $element, string $children, array $lines, bool $padded, string $stick, string $onTop, string $id, string $jump): string
    {
        $only = count($element->children) === 1 ? $element->children[0] : null;
        if ($only === null || $only->namespace !== null || $only->tag !== 'rk-foreach') {
            throw TemplateException::at($element, '<ScrollView virtual="true"> holds exactly one @foreach (its rows)');
        }
        // The rows' gap, inside each row: a virtual list has no layout around its rows.
        if ($padded && preg_match('/^(for [^\n]*? : )/', $children, $m) === 1 && $jump === '') {
            $children = $m[1] . Code::block('VerticalLayout', ['padding-bottom: 8px;'], substr($children, strlen($m[1])));
        }
        $this->context->useVirtualList();
        return Code::stub('scroll-view-virtual', [
            'id' => $id,
            'jump' => $jump,
            'again' => $jump === '' ? '' : Code::block('Timer', ['interval: 40ms;', 'running: false;', 'in-out property <int> goal: -1;', 'in-out property <int> rounds: 0;',
                "triggered => {\n    self.running = false;\n    $id.rk-goal = self.goal;\n}"], '', "$id-again := "),
            'lines' => implode("\n", $lines),
            'rows' => $children,
            'stick' => $stick,
            'threshold' => self::THRESHOLD,
            'onTop' => $onTop,
        ]);
    }

    /**
     * ref="messages" + Screen::scrollTo('messages', $row): every row watches for its index, and the
     * row asked for puts itself in the middle of the view. A virtual list first jumps to an estimate,
     * so that the row exists (only rows in view do), and the row then corrects it.
     *
     * @return array{0: string, 1: string} the rows (each wrapped), the scrollable's own lines
     */
    private function scrollable(Element $element, Scope $scope, string $children, string $id, bool $virtual, string $stick): array
    {
        $ref = $element->attributes['ref'];
        if (preg_match('/^[A-Za-z_][\w-]*$/', $ref) !== 1) {
            throw TemplateException::at($element, "ref=\"$ref\": a name, e.g. ref=\"messages\"");
        }
        if ($scope->model === null || !isset($scope->class->props['scrollRef'])) {
            throw TemplateException::at($element, 'ref="…" on a <ScrollView> works in a screen\'s template (Screen::scrollTo() scrolls it)');
        }
        $only = count($element->children) === 1 ? $element->children[0] : null;
        if ($only === null || $only->namespace !== null || $only->tag !== 'rk-foreach'
            || preg_match('/^for ([\w-]+)(?:\[([\w-]+)\])? in (.*?) : /s', $children, $m) !== 1) {
            throw TemplateException::at($element, 'a <ScrollView ref="…"> holds exactly one @foreach (its rows, which scrollTo() counts)');
        }
        $values = $this->context->values;
        $which = $values->resolve('scrollRef', $scope, $element)->slint;
        $row = $values->resolve('scrollRow', $scope, $element)->slint;
        $count = $values->resolve('scrollCount', $scope, $element)->slint;
        $index = ($m[2] ?? '') !== '' ? $m[2] : 'rk-row';
        $body = substr($children, strlen($m[0]));
        $show = "$id.rk-show(self.y, self.height);";
        $wrapper = Code::block('VerticalLayout', [
            ...($virtual ? ['padding-bottom: ' . ((!isset($element->attributes['padded']) || $this->context->flag($element, 'padded')) ? '8px' : '0px') . ';'] : []),
            "property <bool> rk-hit: $index == $id.rk-goal;",
            "init => {\n    if (self.rk-hit) {\n        $show\n    }\n}",
            "changed rk-hit => {\n    if (self.rk-hit) {\n        $show\n    }\n}",
        ], $body);
        $estimate = $virtual
            ? "\n        self.content-y = max(min(0px, self.height - self.content-height), -(self.content-height * self.rk-goal / max(1, {$m[3]}.length)));"
            : '';
        $jump = implode("\n", [
            '// Screen::scrollTo(): the row asked for (-1 = none); it shows itself when it exists.',
            'property <int> rk-goal: -1;',
            "property <int> rk-asked: $count;",
            "changed rk-asked => {\n    if ($which == \"$ref\" && $row == -2) {\n        // scrollToEnd(): the end, stuck there again (stickToBottom)\n        self.content-y = min(0px, self.height - self.content-height);\n"
                . ($virtual ? "        self.rk-stuck = $stick;\n        $id-settle.running = $stick;\n" : '')
                . "    } else if ($which == \"$ref\") {\n        self.rk-goal = -1;\n        self.rk-goal = $row;" . ($virtual ? "\n        $id-again.rounds = 0;" : '') . "$estimate\n    }\n}",
            "function rk-show(y: length, h: length) {\n    self.content-y = max(min(0px, self.height - self.content-height), min(0px, (self.height - h) / 2 - y));\n"
                . ($virtual ? "    // A reused row reports where it was: once more when the rows around it are in place.\n    if ($id-again.rounds < 2) {\n        $id-again.goal = self.rk-goal;\n        $id-again.rounds += 1;\n        $id-again.running = true;\n    }\n" : '')
                . "    self.rk-goal = -1;\n}",
        ]);
        return ["for {$m[1]}[$index] in {$m[3]} : " . $wrapper, $jump];
    }

    /**
     * keys="true": Page Up / Page Down scroll it by most of a view, even while an input (a chat's
     * composer) has the focus: the window takes those keys first and counts them, and the list
     * follows the counts (window properties reach it wherever it is in the template).
     */
    private function pageKeys(): string
    {
        $page = $this->context->pageGuard() === '' ? '' : '(' . $this->context->pageGuard() . ') && ';
        $this->context->addCapture("if ({$page}event.text == Key.PageUp) {\n    root.rk-page-up += 1;\n    return accept;\n}\n"
            . "if ({$page}event.text == Key.PageDown) {\n    root.rk-page-down += 1;\n    return accept;\n}");
        return implode("\n", [
            '// keys="true": Page Up / Page Down (counted by the window).',
            'property <int> rk-ups: root.rk-page-up;',
            'property <int> rk-downs: root.rk-page-down;',
            "changed rk-ups => {\n    self.content-y = min(0px, self.content-y + self.height * 0.9);\n}",
            "changed rk-downs => {\n    self.content-y = max(min(0px, self.height - self.content-height), self.content-y - self.height * 0.9);\n}",
        ]);
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $this->context->checkAttributes($element, ['grow', 'padded', 'stickToBottom', 'anchor', 'onTop', 'virtual', 'ref', 'keys', 'when', 'each'], styled: true);
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
        $id = 'rk-scroll-' . ++$this->next;
        $virtual = isset($element->attributes['virtual']) && $this->context->flag($element, 'virtual');
        [$children, $jump] = isset($element->attributes['ref'])
            ? $this->scrollable($element, $scope, $children, $id, $virtual, $stick)
            : [$children, ''];
        if ($this->context->flag($element, 'keys')) {
            $jump .= "\n" . $this->pageKeys();
        }
        $lines = [...CompileContext::styleLines($style, $style->box), ...CompileContext::styleLines($style, $style->layout)];
        // It takes the room it's given (a list's natural height is all of it); grow="false" opts out.
        $lines[] = isset($element->attributes['grow']) || $style->flex !== null ? $this->context->stretch($element, $axis)
            : ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: 1;';
        if ($virtual) {
            return $this->context->withMargin($this->virtual($element, $children, $lines, $padded, $stick, $onTop, $id, $jump), $style, $element, $axis);
        }
        $code = Code::stub('scroll-view', [
            'id' => $id,
            'jump' => $jump,
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
