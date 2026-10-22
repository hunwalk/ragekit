<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates\Errors;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** Neither the active kit nor Material (the fallback) provides a component the template needs. */
final class MissingKitElement extends TemplateException
{
    /** @param Element|string $where the element that needs it, or the template file */
    public function __construct(Element|string $where, string $component)
    {
        $file = $where instanceof Element ? $where->file : $where;
        $line = $where instanceof Element ? $where->line : 0;
        parent::__construct($file, $line, "no installed kit provides $component");
    }
}
