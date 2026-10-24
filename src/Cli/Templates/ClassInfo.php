<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\Project\Paths;

/**
 * What a template may refer to in one Component class, or in the app's RootStore subclass and its
 * stores ($isRootStore: props are its stores, store name => Store class; $isStore: a store's members),
 * as read by introspect.php.
 */
final class ClassInfo
{
    /**
     * @param array<string, string>       $props     public properties => type (array → list)
     * @param array<string, string>       $computed  public methods without required params => return type
     * @param array<string, list<string>> $handlers  public methods => parameter types
     * @param array<string, mixed>        $constants class constants (any visibility) => value
     * @param array<string, int>          $requiredParams handler => number of required parameters (missing: all)
     * @param array<string, array{return: string, params: list<string>, required: int}> $contextMembers
     *                                              windows: the methods of the context() interface
     * @param string                     $window    screens: the Window subclass they show in ('' = Window itself)
     * @param list<string>                $interfaces interfaces the class implements
     * @param string                     $templateDeclared what template() returned ('' = not declared);
     *                                              $template is that path resolved against the project root,
     *                                              or ($templateDefault) templates/<path under src/>.rk
     * @param list<array{group: string, class: string, found: bool, constants: array<string, mixed>}> $assets
     *                                              $isApp: what the App subclass's assets() registers
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
        public readonly bool $isWindow = false,
        public readonly string $window = '',
        public readonly string $context = '',
        public readonly array $contextMembers = [],
        public readonly array $interfaces = [],
        public readonly bool $constructible = true,
        public readonly bool $isRootStore = false,
        public readonly bool $isStore = false,
        public readonly bool $templateDefault = false,
        public readonly bool $isApp = false,
        public readonly array $assets = [],
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
            (bool) ($row['isWindow'] ?? false),
            (string) ($row['window'] ?? ''),
            (string) ($row['context'] ?? ''),
            (array) ($row['contextMembers'] ?? []),
            (array) ($row['interfaces'] ?? []),
            (bool) ($row['constructible'] ?? true),
            (bool) ($row['isRootStore'] ?? false),
            (bool) ($row['isStore'] ?? false),
            (bool) ($row['templateDefault'] ?? false),
            (bool) ($row['isApp'] ?? false),
            array_map(fn(array $g) => ['group' => (string) $g['group'], 'class' => (string) $g['class'], 'found' => (bool) $g['found'], 'constants' => (array) $g['constants']], (array) ($row['assets'] ?? [])),
        );
    }
}
