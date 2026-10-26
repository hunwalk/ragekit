<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Kits\Kit;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\Elements\AccordionCompiler;
use RageKit\Cli\Slint\Elements\ChildComponentCompiler;
use RageKit\Cli\Slint\Elements\CompileContext;
use RageKit\Cli\Slint\Elements\ControlFlowCompiler;
use RageKit\Cli\Slint\Elements\DialogCompiler;
use RageKit\Cli\Slint\Elements\ContextMenuCompiler;
use RageKit\Cli\Slint\Elements\DropdownMenuCompiler;
use RageKit\Cli\Slint\Elements\ShortcutCompiler;
use RageKit\Cli\Slint\Elements\ElementCompiler;
use RageKit\Cli\Slint\Elements\KitElementCompiler;
use RageKit\Cli\Slint\Elements\ScrollViewCompiler;
use RageKit\Cli\Slint\Elements\StackCompiler;
use RageKit\Cli\Slint\Elements\TabsCompiler;
use RageKit\Cli\Slint\Elements\ViewCompiler;
use RageKit\Cli\Slint\Elements\WindowFrameCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;

/**
 * Turns template elements into Slint by handing each to the ElementCompiler registered for its tag
 * (Elements/); elements no compiler claims are kit elements. Shared state (what the output
 * imports, overlays, resolved styles) lives in the CompileContext.
 */
final class ElementDispatcher
{
    private readonly CompileContext $context;

    private readonly ControlFlowCompiler $controlFlow;

    private readonly KitElementCompiler $kitElements;

    /** @var array<string, ElementCompiler> tag => the compiler that handles it */
    private array $compilers = [];

    /** @param array<string, ClassInfo> $children short name => child component */
    public function __construct(KitRegistry $kits, string $activeKit, array $children, ValueCompiler $values)
    {
        $this->context = new CompileContext($kits, $activeKit, $children, $values, $this->element(...));
        $this->controlFlow = new ControlFlowCompiler($this->context);
        $this->kitElements = new KitElementCompiler($this->context);
        $stacks = new StackCompiler($this->context);
        // Registration order is lookup priority: a child component can't take a built-in tag.
        foreach ([
            $this->controlFlow,
            new ViewCompiler($this->context, $contextMenus = new ContextMenuCompiler($this->context)),
            $contextMenus,
            new ShortcutCompiler($this->context),
            new WindowFrameCompiler($this->context, $stacks),
            new TabsCompiler($this->context),
            new DialogCompiler($this->context),
            new DropdownMenuCompiler($this->context),
            new AccordionCompiler($this->context),
            new ScrollViewCompiler($this->context),
            $stacks,
            new ChildComponentCompiler($this->context),
        ] as $compiler) {
            foreach ($compiler->tags() as $tag) {
                $this->compilers[$tag] ??= $compiler;
            }
        }
    }

    public function element(Element $element, Scope $scope, string $axis): string
    {
        return $this->controlFlow->attributes($element, $scope, $axis, $this->bare(...)) ?? $this->bare($element, $scope, $axis);
    }

    private function bare(Element $element, Scope $scope, string $axis): string
    {
        $compiler = $element->namespace === null ? ($this->compilers[$element->tag] ?? $this->kitElements) : $this->kitElements;
        return $compiler->compile($element, $scope, $axis);
    }

    /** @return list<string> hints for the author (e.g. old syntax), one per template */
    public function warnings(): array
    {
        return $this->context->warnings();
    }

    /** @return array<string, array<string, true>> */
    public function imports(): array
    {
        return $this->context->imports();
    }

    /** @return array<string, string> */
    public function libraries(): array
    {
        return $this->context->libraries();
    }

    public function useKit(Kit $kit, string $component): void
    {
        $this->context->useKit($kit, $component);
    }

    /** The screen's root element: a root <View> fills the window. */
    public function markRoot(Element $element): void
    {
        $this->context->markRoot($element);
    }

    /** @return string the dialogs collected since the last call, for the window's top level */
    public function takeOverlays(): string
    {
        return $this->context->takeOverlays();
    }

    /** The keyboard shortcuts (<Shortcut>) collected since the last call, for the window's key handler. */
    public function takeShortcuts(): string
    {
        return $this->context->takeShortcuts();
    }

    /** Dialogs compiled from now on show only while this page is (see CompileContext::pageGuard). */
    public function setPageGuard(string $condition): void
    {
        $this->context->setPageGuard($condition);
    }

    public function flag(Element $element, string $name): bool
    {
        return $this->context->flag($element, $name);
    }
}
