<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Kits\ElementSpec;
use RageKit\Cli\Kits\Kit;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\ExpressionCompiler;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\StyleProperties;
use RageKit\Cli\Slint\StyleResolver;
use RageKit\Cli\Slint\StyleResult;
use RageKit\Cli\Slint\ValueCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\UnknownAttribute;
use RageKit\Cli\Templates\TemplateException;

/**
 * What every element compiler shares while one app's templates compile: the kits, the value,
 * expression and style compilers, what the output imports, and the helpers for attributes,
 * styles and stretching. element() compiles a child through the dispatcher.
 */
final class CompileContext
{
    public readonly ExpressionCompiler $expressions;

    public readonly StyleResolver $styles;

    /** @var array<string, array<string, true>> kit entry file => Slint components used from it */
    private array $imports = [];

    /** @var array<string, string> Slint libraries the used kits need (name => path) */
    private array $libraries = [];

    /** @var array<string, string> template file => deprecation hint, once per file */
    private array $warnings = [];

    /** @var array<int, StyleResult> resolved styles by element (spl_object_id) */
    private array $resolved = [];

    /** @var array<int, true> elements whose parent is a <View>, where position: absolute works */
    private array $inView = [];

    /** @var array<int, true> root <View>s of a screen: they fill the window */
    private array $roots = [];

    /** How many for/if (directives, each=, when=) the element being compiled is inside. */
    private int $guarded = 0;

    /** @var list<string> dialogs of the screen being compiled, for the window's top level (takeOverlays) */
    private array $overlays = [];

    /**
     * @param array<string, ClassInfo> $children short name => child component
     * @param \Closure(Element, Scope, string): string $element compiles any element (the dispatcher)
     */
    public function __construct(
        public readonly KitRegistry $kits,
        public readonly string $activeKit,
        public readonly array $children,
        public readonly ValueCompiler $values,
        private readonly \Closure $element,
    ) {
        $this->expressions = new ExpressionCompiler($values);
        $this->styles = new StyleResolver($values);
    }

    public function element(Element $element, Scope $scope, string $axis): string
    {
        return ($this->element)($element, $scope, $axis);
    }

    /** @param list<string> $reserved the element's own attributes that look like style properties */
    public function styleOf(Element $element, Scope $scope, string $kind, array $reserved = []): StyleResult
    {
        $id = spl_object_id($element);
        if (!isset($this->resolved[$id])) {
            $style = $this->styles->resolve($element, $scope, $kind, $reserved);
            if ($style->position === 'absolute' && !isset($this->inView[$id])) {
                throw TemplateException::at($element, 'position: absolute needs a <View> parent');
            }
            $this->resolved[$id] = $style;
        }
        return $this->resolved[$id];
    }

    /** The style styleOf() resolved for an element, if it was compiled already. */
    public function resolved(Element $element): ?StyleResult
    {
        return $this->resolved[spl_object_id($element)] ?? null;
    }

    public function markInView(Element $element): void
    {
        $this->inView[spl_object_id($element)] = true;
    }

    /** The screen's root element: a root <View> fills the window. */
    public function markRoot(Element $element): void
    {
        $this->roots[spl_object_id($element)] = true;
    }

    public function isRoot(Element $element): bool
    {
        return isset($this->roots[spl_object_id($element)]);
    }

    /** @param array<string, string> $map style properties to emit; @return list<string> Slint lines */
    public static function styleLines(StyleResult $style, array $map): array
    {
        $lines = [];
        foreach ($map as $property => $value) {
            $lines[] = "$property: $value;";
        }
        return [...$lines, ...$style->animations(array_keys($map))];
    }

    /** margin: the element inside a padded single-cell layout (Slint has padding, not margin). */
    public function withMargin(string $code, StyleResult $style, Element $element, string $axis): string
    {
        if ($style->margin === null) {
            return $code;
        }
        [$top, $right, $bottom, $left] = $style->margin;
        return Code::block('HorizontalLayout', [
            "padding-top: $top;",
            "padding-right: $right;",
            "padding-bottom: $bottom;",
            "padding-left: $left;",
            $this->stretch($element, $axis),
        ], $code);
    }

    public function stretch(Element $element, string $axis): string
    {
        $flex = $this->resolved[spl_object_id($element)]->flex ?? null;
        return ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: ' . ($flex ?? ($this->flag($element, 'grow') ? '1' : '0')) . ';';
    }

    public function flag(Element $element, string $name): bool
    {
        $value = $element->attributes[$name] ?? 'false';
        if ($value !== 'true' && $value !== 'false') {
            throw TemplateException::at($element, "$name=\"$value\" must be \"true\" or \"false\"");
        }
        return $value === 'true';
    }

    /**
     * @param list<string> $allowed
     * @param bool $styled whether the element applies style attributes (style, @style, width="…")
     */
    public function checkAttributes(Element $element, array $allowed, bool $styled = false): void
    {
        $styling = $styled ? ['style', 'rk-style', ...StyleProperties::names()] : [];
        foreach (array_keys($element->attributes) as $name) {
            if (!in_array($name, $allowed, true) && !in_array($name, $styling, true)) {
                throw new UnknownAttribute($element, $name, $allowed);
            }
        }
    }

    /** @return array{0: Kit, 1: ElementSpec}|null a core element from the active kit, else Material */
    public function coreSpec(string $tag): ?array
    {
        foreach ([$this->activeKit, 'material'] as $name) {
            $kit = $this->kits->get($name);
            if (isset($kit->elements[$tag])) {
                return [$kit, $kit->elements[$tag]];
            }
        }
        return null;
    }

    public function useKit(Kit $kit, string $component): void
    {
        $this->imports[$kit->entry][$component] = true;
        $this->libraries += $kit->libraries;
    }

    /** @return array<string, array<string, true>> */
    public function imports(): array
    {
        return $this->imports;
    }

    /** @return array<string, string> */
    public function libraries(): array
    {
        return $this->libraries;
    }

    /** A hint for the author, shown once per template file. */
    public function warnOnce(string $file, string $warning): void
    {
        $this->warnings[$file] ??= $warning;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values($this->warnings);
    }

    /**
     * Compiles inside a for/if (directives, each=, when=).
     *
     * @param \Closure(): string $compile
     */
    public function guarded(\Closure $compile): string
    {
        $this->guarded++;
        try {
            return $compile();
        } finally {
            $this->guarded--;
        }
    }

    /** Whether the element being compiled is inside a for/if. */
    public function isGuarded(): bool
    {
        return $this->guarded > 0;
    }

    /** Reserves the next place at the window's top level; fill it with setOverlay(). */
    public function reserveOverlay(): int
    {
        $this->overlays[] = '';
        return count($this->overlays) - 1;
    }

    public function setOverlay(int $slot, string $code): void
    {
        $this->overlays[$slot] = $code;
    }

    /** @return string the overlays collected since the last call, for the window's top level */
    public function takeOverlays(): string
    {
        $overlays = implode('', $this->overlays);
        $this->overlays = [];
        return $overlays;
    }
}
