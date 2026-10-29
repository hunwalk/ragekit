<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\IncompleteKitElement;
use RageKit\Cli\Templates\Errors\MissingKitElement;
use RageKit\Cli\Templates\Errors\NoChildrenAllowed;
use RageKit\Cli\Templates\TemplateException;

/** A Window subclass's frame pieces: <Content /> (where the screen goes), <WindowDrag>, <WindowControls />. */
final class WindowFrameCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context, private readonly StackCompiler $stack)
    {
    }

    public function tags(): array
    {
        return ['Content', 'WindowDrag', 'WindowControls'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        if (!$scope->inWindow() || $scope->hasItems() && $element->tag === 'Content') {
            $name = $element->tag === 'Content' ? '<Content />' : "<{$element->tag}>";
            throw TemplateException::at($element, $scope->inWindow()
                ? "$name can't be repeated with each="
                : "$name is only allowed in a Window's template");
        }
        return match ($element->tag) {
            'Content' => $this->content($element, $scope, $axis),
            'WindowDrag' => $this->windowDrag($element, $scope, $axis),
            default => $this->windowControls($element, $axis),
        };
    }

    /**
     * A window template's <Content />: each screen of the window, padded as a plain window would
     * be (none for a root stack with padded="false"); with several screens, each one is shown while
     * rk-page is its page.
     */
    private function content(Element $element, Scope $scope, string $axis): string
    {
        $this->context->checkAttributes($element, []);
        if ($element->children !== []) {
            throw new NoChildrenAllowed($element);
        }
        if ($scope->pages === []) {
            throw TemplateException::at($element, "<Content /> is only allowed in a Window's template");
        }
        $out = '';
        foreach ($scope->pages as $page) {
            $guard = count($scope->pages) > 1 ? "root.rk-page == {$page->index}" : '';
            $this->context->setPageGuard($guard);
            $body = $this->context->element($page->root, $page->scope, 'v');
            $this->context->setPageGuard('');
            $out .= Code::block('VerticalLayout', [
                'padding: ' . (StackCompiler::flush($page->root) ? '0px' : '16px') . ';',
                'alignment: ' . ($this->context->flag($page->root, 'grow') ? 'stretch' : 'start') . ';',
                ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: 1;',
            ], $body, $guard === '' ? '' : "if $guard : ");
        }
        return $out;
    }

    /** <WindowDrag>: an <HStack> whose background moves the window (a double-click, found natively, maximises). */
    private function windowDrag(Element $element, Scope $scope, string $axis): string
    {
        $stack = $this->stack->compile($element, $scope, $axis);
        return Code::stub('window-drag', ['stretch' => $this->context->stretch($element, $axis), 'stack' => $stack]);
    }

    /** <WindowControls />: the kit's minimise / maximise / close buttons, handled natively. */
    private function windowControls(Element $element, string $axis): string
    {
        $this->context->checkAttributes($element, ['grow', 'when']);
        if ($element->children !== []) {
            throw new NoChildrenAllowed($element);
        }
        [$kit, $spec] = $this->context->coreSpec('WindowControls') ?? throw new MissingKitElement($element, 'WindowControls');
        $this->context->useKit($kit, $spec->component);
        $lines = [];
        if (isset($spec->props['maximized'])) {
            $lines[] = "{$spec->props['maximized']->property}: root.rk-maximized;";
        }
        foreach (['onMinimize' => 'minimize', 'onToggleMaximize' => 'toggle-maximize', 'onClose' => 'close'] as $event => $command) {
            $callback = $spec->events[$event] ?? throw new IncompleteKitElement($element, $kit, 'WindowControls', "$event event");
            $lines[] = "$callback => { root.rk-window(\"$command\"); }";
        }
        $lines[] = $this->context->stretch($element, $axis);
        return Code::block($spec->component, $lines);
    }
}
