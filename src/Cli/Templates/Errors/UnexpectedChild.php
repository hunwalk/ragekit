<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** <Tabs><Label /></Tabs>: a parent that only takes one kind of child got another. */
final class UnexpectedChild extends TemplateException
{
    public function __construct(Element $child, string $parent, string $allowed, string $hint = '')
    {
        parent::__construct($child->file, $child->line, "<$parent> may only contain <$allowed> elements" . ($hint === '' ? '' : " ($hint)"));
    }
}
