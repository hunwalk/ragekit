<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** <Tab> outside <Tabs>: an element that only works directly inside one parent. */
final class MisplacedElement extends TemplateException
{
    public function __construct(Element $element, string $parent)
    {
        parent::__construct($element->file, $element->line, "<{$element->tag}> is only allowed directly inside <$parent>");
    }
}
