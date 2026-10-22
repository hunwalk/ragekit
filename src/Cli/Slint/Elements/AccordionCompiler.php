<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Kits\PropSpec;
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

/**
 * <Accordion open="{{ $open }}"><Section title="…">one element</Section>…</Accordion>: the kit's
 * AccordionHeader per section; one section open at a time (open = its index, -1 = none). Like
 * <Tabs>, closed sections are removed and their state is kept in PHP.
 */
final class AccordionCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['Accordion', 'Section'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        if ($element->tag === 'Section') {
            throw new MisplacedElement($element, 'Accordion');
        }
        $this->context->checkAttributes($element, ['open', 'grow', 'when', 'each']);
        [$kit, $spec] = $this->context->coreSpec('AccordionHeader') ?? throw new MissingKitElement($element, 'AccordionHeader');
        $raw = $element->attributes['open'] ?? '';
        $binding = $this->context->values->twoWayBinding($raw, new PropSpec('int', 'open', null, 'changed'), $scope, $element, 'open');
        if ($binding === null || $binding->type !== 'int') {
            throw TemplateException::at($element, "<Accordion open=\"$raw\">: open must bind an int property, e.g. open=\"{{ \$open }}\" (-1: all closed)");
        }
        $action = $scope->model?->action('set', $binding->name, [], $binding, $binding->owner);
        $this->context->useKit($kit, $spec->component);
        $title = $spec->props['title']->property ?? 'title';
        $open = $spec->props['open']->property ?? 'open';
        $toggled = $spec->events['onToggle'] ?? throw new IncompleteKitElement($element, $kit, 'AccordionHeader', 'onToggle event');
        $p = 'root.' . $binding->slintName();
        $out = '';
        foreach ($element->children as $i => $section) {
            if ($section->namespace !== null || $section->tag !== 'Section') {
                throw new UnexpectedChild($section, 'Accordion', 'Section');
            }
            $this->context->checkAttributes($section, ['title']);
            if (count($section->children) !== 1) {
                throw new OneChildRequired($section, '<Section>');
            }
            $out .= Code::stub('accordion-section', [
                'header' => $spec->component,
                'title' => $title,
                'titleValue' => TextAttribute::slintString($section->attributes['title'] ?? ''),
                'open' => $open,
                'toggled' => $toggled,
                'selected' => $p,
                'index' => (string) $i,
                'action' => (string) $action?->id,
                'body' => $this->context->element($section->children[0], $scope, 'v'),
            ]);
        }
        return Code::block('VerticalLayout', ['spacing: 0px;', 'alignment: start;', $this->context->stretch($element, $axis)], $out);
    }
}
