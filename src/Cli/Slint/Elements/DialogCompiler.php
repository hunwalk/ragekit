<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Kits\PropSpec;
use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\IncompleteKitElement;
use RageKit\Cli\Templates\Errors\MissingKitElement;
use RageKit\Cli\Templates\TemplateException;

/**
 * <Dialog open="{{ $confirm }}" title="…">…</Dialog>: the kit's Dialog (a scrim over the whole
 * window with a centred panel) shown while the two-way bool `open` is true. It's placed at the
 * window's top level, wherever it's written, so it covers everything (also a layout's title
 * bar); an outside click or Esc sets `open` back to false. Leaves nothing where it's written.
 */
final class DialogCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['Dialog'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $context = $this->context;
        $context->checkAttributes($element, ['open', 'title']);
        if ($scope->model === null) {
            throw TemplateException::at($element, "<Dialog> can't be in a child component's template; put it in the screen's (or layout's) template");
        }
        if ($context->isGuarded()) {
            throw TemplateException::at($element, "<Dialog> can't be inside @if, @foreach, each= or when=; use open=\"{{ \$prop }}\" to show it");
        }
        [$kit, $spec] = $context->coreSpec('Dialog') ?? throw new MissingKitElement($element, 'Dialog');
        $raw = $element->attributes['open'] ?? throw TemplateException::at($element, '<Dialog> needs open="{{ $prop }}", a bool property that shows it');
        $binding = $context->values->twoWayBinding($raw, new PropSpec('bool', 'open', null, 'changed'), $scope, $element, 'open');
        if ($binding === null || $binding->type !== 'bool') {
            throw TemplateException::at($element, "<Dialog open=\"$raw\">: open must bind a bool property, e.g. open=\"{{ \$confirmDelete }}\"");
        }
        $action = $scope->model->action('set', $binding->name, [], $binding, $binding->owner);
        $dismissed = $spec->events['onDismiss'] ?? throw new IncompleteKitElement($element, $kit, 'Dialog', 'onDismiss event');
        $context->useKit($kit, $spec->component);
        $p = 'root.' . $binding->slintName();
        $lines = ['x: 0;', 'y: 0;', 'width: root.width;', 'height: root.height;'];
        if (isset($element->attributes['title'])) {
            $title = $spec->props['title']->property ?? 'title';
            $lines[] = "$title: " . $context->values->value($element->attributes['title'], 'string', null, $scope, $element, 'title') . ';';
        }
        $lines[] = "$dismissed => { $p = false; root.action({$action->id}, \"0\"); }";
        // Its slot comes first, so a dialog inside it (compiled below) is drawn on top of it.
        $slot = $context->reserveOverlay();
        $children = '';
        foreach ($element->children as $child) {
            $children .= $context->element($child, $scope, 'v');
        }
        $context->setOverlay($slot, Code::block($spec->component, $lines, $children, 'if ' . ($context->pageGuard() === '' ? '' : $context->pageGuard() . ' && ') . "$p : "));
        return '';
    }
}
