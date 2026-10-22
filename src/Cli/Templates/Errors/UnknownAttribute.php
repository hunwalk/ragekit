<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** <Button colour="red" />: an attribute the element doesn't take. */
final class UnknownAttribute extends TemplateException
{
    /** @param list<string> $allowed */
    public function __construct(Element $element, string $name, array $allowed)
    {
        parent::__construct($element->file, $element->line, "<{$element->tag}> has no attribute '$name' (allowed: " . implode(', ', $allowed) . ')');
    }
}
