<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** <Tab>, <Section>, @foreach and @if branches hold exactly one element. */
final class OneChildRequired extends TemplateException
{
    /** @param string $what how the author wrote it: "<Tab>", "@foreach", "each @if branch" */
    public function __construct(Element $element, string $what)
    {
        parent::__construct($element->file, $element->line, "$what must contain exactly one element; wrap several in a <View> or <VStack>");
    }
}
