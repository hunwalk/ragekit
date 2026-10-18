<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\RageKitException;

/** A tag a kit provides: which Slint component, which attributes and events (kit.yml `elements:`). */
final class ElementSpec
{
    /**
     * @param array<string, PropSpec> $props  attribute => spec
     * @param array<string, string>   $events attribute (onClick) => Slint callback (clicked)
     */
    public function __construct(
        public readonly string $component,
        public readonly array $props,
        public readonly array $events,
        public readonly bool $children,
    ) {
    }

    public static function fromArray(mixed $raw, string $where): self
    {
        if (!is_array($raw) || !isset($raw['component'])) {
            throw new RageKitException("$where: needs a component");
        }
        $props = [];
        foreach ($raw['props'] ?? [] as $name => $prop) {
            $props[(string) $name] = PropSpec::fromYaml((string) $name, $prop, "$where.props.$name");
        }
        return new self(
            (string) $raw['component'],
            $props,
            array_map('strval', $raw['events'] ?? []),
            (bool) ($raw['children'] ?? false),
        );
    }
}
