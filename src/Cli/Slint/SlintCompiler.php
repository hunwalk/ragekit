<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\MissingKitElement;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Cli\Templates\TemplateParser;

/**
 * Compiles the app's .rk templates into one Slint file: a `component` per child Component (in
 * dependency order — Slint rejects use before definition) and an exported window component per
 * Screen. Records per screen which PHP values feed which Slint property and which handler each
 * callback runs.
 */
final class SlintCompiler
{
    /** @var array<string, ClassInfo> short name => child component */
    private array $children = [];

    private ValueCompiler $values;
    private ElementDispatcher $elements;

    public function __construct(
        private readonly KitRegistry $kits,
        private readonly string $activeKit,
        private readonly string $projectRoot = '',
        private readonly bool $dev = false,
    ) {
    }

    /** @param array<string, ClassInfo> $classes by class name */
    public function compile(array $classes): CompiledViews
    {
        $owners = [];
        foreach ($classes as $class) {
            if (isset($owners[$class->short])) {
                throw new TemplateException($class->file, 0, "two components are named {$class->short} ({$owners[$class->short]} and {$class->name}); component class names must be unique");
            }
            $owners[$class->short] = $class->name;
            if ($class->template === '') {
                throw new TemplateException($class->file, 0, "{$class->name} doesn't declare template()");
            }
            if (!is_file($class->template)) {
                throw new TemplateException($class->file, 0, "{$class->short}::template() points to {$class->templateDeclared}, which doesn't exist ({$class->template})");
            }
            if (!$class->isScreen && !$class->isLayout) {
                $this->children[$class->short] = $class;
            }
        }

        $this->values = new ValueCompiler($this->projectRoot);
        $this->elements = new ElementDispatcher($this->kits, $this->activeKit, $this->children, $this->values);
        $window = $this->windowComponent();
        $definitions = [];
        foreach ($this->childOrder() as $child) {
            $definitions[] = $this->childComponent($child);
        }
        // Screens sharing a layout (or sharing none) are one window component, a page each.
        $groups = [];
        foreach ($classes as $class) {
            if ($class->isScreen) {
                $layout = $this->layoutOf($class, $classes);
                $groups[WindowModel::nameFor($layout)][] = [$class, $layout];
            }
        }
        ksort($groups);
        $screens = [];
        $windows = [];
        foreach ($groups as $name => $members) {
            usort($members, fn(array $a, array $b) => strcmp($a[0]->name, $b[0]->name));
            $model = new WindowModel($name, $members[0][1]);
            foreach ($members as [$class, $layout]) {
                $screens[] = new ScreenModel($class, $layout, $model);
            }
            $definitions[] = $this->windowComponentFor($model, $window);
            $windows[] = $model;
        }

        $imports = [];
        foreach ($this->elements->imports() as $file => $components) {
            $imports[] = 'import { ' . implode(', ', array_keys($components)) . ' } from ' . TextAttribute::slintString($file) . ';';
        }
        return new CompiledViews(
            Code::stub('app', ['imports' => implode("\n", $imports), 'components' => implode("\n", $definitions)]),
            $screens,
            $this->elements->libraries(),
            array_keys($this->elements->imports()),
            $this->values->images(),
            $this->elements->warnings(),
            $windows,
        );
    }

    private function windowComponent(): string
    {
        $kit = $this->kits->get($this->activeKit);
        if ($kit->window === null) {
            $kit = $this->kits->get('material');
        }
        $this->elements->useKit($kit, (string) $kit->window);
        return (string) $kit->window;
    }

    /** @return list<ClassInfo> children, each after the children it uses */
    private function childOrder(): array
    {
        $uses = [];
        foreach ($this->children as $short => $child) {
            $uses[$short] = $this->usedChildren(TemplateParser::parseFile($child->template));
        }
        $ordered = [];
        $state = [];
        $visit = function (string $short, array $path) use (&$visit, &$ordered, &$state, $uses): void {
            if (($state[$short] ?? null) === 'done') {
                return;
            }
            if (($state[$short] ?? null) === 'visiting') {
                $child = $this->children[$short];
                throw new TemplateException($child->template, 0, 'components use each other in a cycle: ' . implode(' → ', [...$path, $short]));
            }
            $state[$short] = 'visiting';
            foreach ($uses[$short] as $used) {
                $visit($used, [...$path, $short]);
            }
            $state[$short] = 'done';
            $ordered[] = $this->children[$short];
        };
        foreach (array_keys($this->children) as $short) {
            $visit($short, []);
        }
        return $ordered;
    }

    /** @return list<string> */
    private function usedChildren(Element $element): array
    {
        $used = $element->namespace === null && isset($this->children[$element->tag]) ? [$element->tag] : [];
        foreach ($element->children as $child) {
            $used = [...$used, ...$this->usedChildren($child)];
        }
        return array_values(array_unique($used));
    }

    private function childComponent(ClassInfo $class): string
    {
        $root = TemplateParser::parseFile($class->template);
        $properties = [];
        foreach ($class->props as $name => $type) {
            $properties[] = 'in property <' . ValueCompiler::TYPES[$this->values->valueType($type, $root, "property \$$name of {$class->short}")] . "> rk-$name;";
        }
        return Code::stub('child-component', [
            'name' => $class->short,
            'properties' => implode("\n", $properties),
            'body' => $this->elements->element($root, new Scope($class, null), 'v'),
        ]);
    }

    /**
     * The Layout a screen's layout() names, checked: it exists, can be created by the runtime, and
     * the screen implements its context() interface.
     *
     * @param array<string, ClassInfo> $classes
     */
    private function layoutOf(ClassInfo $screen, array $classes): ?ClassInfo
    {
        if ($screen->layout === '') {
            return null;
        }
        $layout = $classes[$screen->layout] ?? null;
        if ($layout === null || !$layout->isLayout) {
            throw new TemplateException($screen->file, 0, "{$screen->short}::layout() names {$screen->layout}, which isn't a Layout of this app");
        }
        if (!$layout->constructible) {
            throw new TemplateException($layout->file, 0, "{$layout->short} must be constructible without arguments: RageKit creates one for every window it frames");
        }
        if ($layout->context !== '' && !in_array($layout->context, $screen->interfaces, true)) {
            throw new TemplateException($screen->file, 0, "{$screen->short} uses {$layout->short}, which needs {$layout->context}; implement it");
        }
        return $layout;
    }

    private function windowComponentFor(WindowModel $model, string $window): string
    {
        $layout = $model->layout;
        $pages = [];
        foreach ($model->pages as $page) {
            $pages[] = new Page($page->page, new Scope($page->class, $page), TemplateParser::parseFile($page->class->template));
        }
        $several = count($pages) > 1;
        if ($layout !== null) {
            $frame = TemplateParser::parseFile($layout->template);
            if (self::count($frame, 'Content') !== 1) {
                throw new TemplateException($layout->template, 0, "{$layout->short}'s template needs exactly one <Content /> (where the screen goes)");
            }
            // A layout's window: frameless, its template fills it edge to edge, resize grips on top.
            $body = $this->elements->element($frame, new Scope($layout, $model->pages[0], [], 'layout', $pages), 'v');
            $body = Code::block('VerticalLayout', ['padding: 0px;', 'alignment: stretch;'], $body);
        } else {
            $body = '';
            foreach ($pages as $page) {
                $guard = $several ? "root.rk-page == {$page->index}" : '';
                $this->elements->setPageGuard($guard);
                $body .= $this->pageBody($page, $guard, !$several);
                $this->elements->setPageGuard('');
            }
            if ($several) {
                // In a layout, like <Tabs> pages: an `if` straight under the window wouldn't pass its
                // minimum size on, and the window could shrink below the page.
                $body = Code::block('VerticalLayout', ['padding: 0px;', 'spacing: 0px;', 'alignment: stretch;'], $body);
            }
        }
        $overlays = $this->elements->takeOverlays() . $this->toasts($model);
        if ($layout !== null) {
            $overlays .= self::grips();
        }
        $properties = [];
        $bindings = array_values($model->bindings);
        foreach ($model->pages as $page) {
            $bindings = [...$bindings, ...array_values($page->bindings)];
        }
        usort($bindings, fn(Binding $a, Binding $b) => $a->id <=> $b->id);
        foreach ($bindings as $binding) {
            $properties[] = ($binding->twoWay ? 'in-out' : 'in') . ' property <' . ValueCompiler::TYPES[$binding->type] . '> ' . $binding->slintName() . ';';
        }
        return Code::stub('window', [
            'name' => $model->name,
            'window' => $window,
            'dev' => $this->dev ? Code::stub('window-dev', []) : '',
            'frameless' => $layout !== null ? Code::stub('window-frameless', []) : '',
            'properties' => implode("\n", $properties),
            'body' => $body,
            'overlays' => $overlays,
        ]);
    }

    /**
     * A screen without a layout: a root <View> fills the window itself ($alone: the window's only
     * page; among several, the pages' layout stretches it); anything else sits in a padded column.
     */
    private function pageBody(Page $page, string $guard, bool $alone): string
    {
        $root = $page->root;
        $prefix = $guard === '' ? '' : "if $guard : ";
        if ($root->namespace === null && $root->tag === 'View') {
            if ($alone) {
                $this->elements->markRoot($root); // like React Native's root: it fills the window
            }
            return $prefix . $this->elements->element($root, $page->scope, 'v');
        }
        $body = $this->elements->element($root, $page->scope, 'v');
        return Code::block('VerticalLayout', ['padding: 16px;', 'alignment: ' . ($this->elements->flag($root, 'grow') ? 'stretch' : 'start') . ';'], $body, $prefix);
    }

    /**
     * Every window's toasts ($this->toast() in PHP): Navigator::toastTexts() as a list, shown
     * bottom-right with the kit's Toast; a timer ticks (action -2) while any is shown, so PHP
     * drops the expired ones.
     */
    private function toasts(WindowModel $model): string
    {
        $kit = $this->kits->get($this->activeKit);
        if (!isset($kit->elements['Toast'])) {
            $kit = $this->kits->get('material');
        }
        $spec = $kit->elements['Toast'] ?? throw new MissingKitElement($model->pages[0]->class->template, 'Toast');
        $this->elements->useKit($kit, $spec->component);
        $p = 'root.' . $model->bind('method', 'toastTexts', 'list', 'window')->slintName();
        $text = $spec->props['text']->property ?? 'text';
        return Code::stub('toasts', ['list' => $p, 'toast' => $spec->component, 'text' => $text]);
    }

    private static function count(Element $element, string $tag): int
    {
        $count = $element->namespace === null && $element->tag === $tag ? 1 : 0;
        foreach ($element->children as $child) {
            $count += self::count($child, $tag);
        }
        return $count;
    }

    /** Invisible strips along a frameless window's edges and corners that resize it natively. */
    private static function grips(): string
    {
        $edge = '6px';
        $corner = '12px';
        $grips = [
            // edge => [x, y, width, height, cursor]
            'top-left' => ['0', '0', $corner, $corner, 'nwse-resize'],
            'top' => [$corner, '0', "root.width - 2 * $corner", $edge, 'ns-resize'],
            'top-right' => ["root.width - $corner", '0', $corner, $corner, 'nesw-resize'],
            'right' => ["root.width - $edge", $corner, $edge, "root.height - 2 * $corner", 'ew-resize'],
            'bottom-right' => ["root.width - $corner", "root.height - $corner", $corner, $corner, 'nwse-resize'],
            'bottom' => [$corner, "root.height - $edge", "root.width - 2 * $corner", $edge, 'ns-resize'],
            'bottom-left' => ['0', "root.height - $corner", $corner, $corner, 'nesw-resize'],
            'left' => ['0', $corner, $edge, "root.height - 2 * $corner", 'ew-resize'],
        ];
        $out = '';
        foreach ($grips as $edge => [$x, $y, $width, $height, $cursor]) {
            $out .= Code::stub('resize-grip', ['edge' => $edge, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'cursor' => $cursor]);
        }
        return $out;
    }
}
