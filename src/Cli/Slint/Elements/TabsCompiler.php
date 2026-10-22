<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\TextAttribute;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\IncompleteKitElement;
use RageKit\Cli\Templates\Errors\MisplacedElement;
use RageKit\Cli\Templates\Errors\MissingKitElement;
use RageKit\Cli\Templates\Errors\OneChildRequired;
use RageKit\Cli\Templates\Errors\UnexpectedChild;
use RageKit\Cli\Templates\TemplateException;

/** <Tabs selected="{{ $tab }}"><Tab title="…">one element</Tab>…</Tabs>: the kit's TabBar + one page per tab. */
final class TabsCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['Tabs', 'Tab'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        if ($element->tag === 'Tab') {
            throw new MisplacedElement($element, 'Tabs');
        }
        $this->context->checkAttributes($element, ['selected', 'grow', 'when', 'each']);
        [$kit, $spec] = $this->context->coreSpec('TabBar') ?? throw new MissingKitElement($element, 'TabBar');
        $selected = $spec->props['selected'] ?? throw new IncompleteKitElement($element, $kit, 'TabBar', 'selected prop');
        $titlesProp = $spec->props['titles'] ?? throw new IncompleteKitElement($element, $kit, 'TabBar', 'titles prop');
        $raw = $element->attributes['selected'] ?? '';
        $binding = $this->context->values->twoWayBinding($raw, $selected, $scope, $element, 'selected')
            ?? throw TemplateException::at($element, "<Tabs selected=\"$raw\">: selected must bind an int property, e.g. selected=\"{tab}\"");
        $titles = [];
        foreach ($element->children as $tab) {
            if ($tab->namespace !== null || $tab->tag !== 'Tab') {
                throw new UnexpectedChild($tab, 'Tabs', 'Tab');
            }
            $this->context->checkAttributes($tab, ['title']);
            if (TextAttribute::hasRefs($tab->attributes['title'] ?? '')) {
                throw TemplateException::at($tab, "title=\"{$tab->attributes['title']}\": tab titles are fixed text; bindings aren't supported there yet");
            }
            if (count($tab->children) !== 1) {
                throw new OneChildRequired($tab, '<Tab>');
            }
            $titles[] = TextAttribute::slintString($tab->attributes['title'] ?? '');
        }
        $this->context->useKit($kit, $spec->component);
        $action = $scope->model?->action('set', $binding->name, [], $binding, $binding->owner);
        $bar = Code::block($spec->component, [
            "{$titlesProp->property}: [" . implode(', ', $titles) . '];',
            "{$selected->property} <=> root.{$binding->slintName()};",
            "{$selected->twoWay}(value) => { root.action({$action?->id}, \"\\{value}\"); }",
        ]);
        $pages = '';
        foreach ($element->children as $i => $tab) {
            $pages .= Code::block('VerticalLayout', ['padding: 0px;'], $this->context->element($tab->children[0], $scope, 'v'), "if root.{$binding->slintName()} == $i : ");
        }
        return Code::block('VerticalLayout', ['spacing: 12px;', 'alignment: start;', $this->context->stretch($element, $axis)], $bar . $pages);
    }
}
