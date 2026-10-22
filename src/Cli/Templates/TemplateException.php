<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\RageKitException;

/**
 * A problem in a .rk template (or in what it refers to), reported as file:line: message. Kinds of
 * problem that come up in many places have their own subclass in Errors/ (tests can check the type).
 */
class TemplateException extends RageKitException
{
    public function __construct(public readonly string $templateFile, public readonly int $templateLine, string $message)
    {
        parent::__construct(($templateLine > 0 ? "$templateFile:$templateLine" : $templateFile) . ": $message");
    }

    /** A problem at a template element. */
    public static function at(Element $element, string $message): self
    {
        return new self($element->file, $element->line, $message);
    }
}
