<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Kits\Kit;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** A kit's component lacks a prop or event the core element contract requires (a kit bug). */
final class IncompleteKitElement extends TemplateException
{
    /** @param string $missing e.g. "onSelect event", "titles prop" */
    public function __construct(Element $element, Kit $kit, string $component, string $missing)
    {
        parent::__construct($element->file, $element->line, "kit {$kit->name}: $component has no $missing");
    }
}
