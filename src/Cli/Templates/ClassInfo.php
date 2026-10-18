<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\Project\Paths;

/** What a template may refer to in one Component class (as read by introspect.php). */
final class ClassInfo
{
    /**
     * @param array<string, string>       $props     public properties => type (array → list)
     * @param array<string, string>       $computed  public methods without required params => return type
     * @param array<string, list<string>> $handlers  public methods => parameter types
     * @param array<string, mixed>        $constants class constants (any visibility) => value
     * @param array<string, int>          $requiredParams handler => number of required parameters (missing: all)
     * @param string                     $templateDeclared what template() returned ('' = not declared);
     *                                              $template is that path resolved against the project root
     */
    public function __construct(
        public readonly string $name,
        public readonly string $short,
        public readonly string $file,
        public readonly bool $isScreen,
        public readonly string $template,
        public readonly array $props = [],
        public readonly array $computed = [],
        public readonly array $handlers = [],
        public readonly array $constants = [],
        public readonly array $requiredParams = [],
        public readonly string $templateDeclared = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            $row['name'],
            $row['short'],
            Paths::normalize($row['file']),
            $row['screen'],
            $row['template'] === '' ? '' : Paths::normalize($row['template']),
            $row['props'],
            $row['computed'],
            $row['handlers'],
            $row['constants'],
            (array) ($row['requiredParams'] ?? []),
            (string) ($row['templateDeclared'] ?? ''),
        );
    }
}
