<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** <Button><Label /></Button>: elements inside an element that takes none. */
final class NoChildrenAllowed extends TemplateException
{
    public function __construct(Element $element)
    {
        parent::__construct($element->file, $element->line, "<{$element->tag}> can't contain other elements");
    }
}
