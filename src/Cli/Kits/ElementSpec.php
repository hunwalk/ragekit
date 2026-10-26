<?php

declare(strict_types=1);

namespace RageKit\Cli\Kits;

use RageKit\Cli\RageKitException;

/** A tag a kit provides: which Slint component, which attributes and events (kit.yml `elements:`). */
final class ElementSpec
{
    /**
     * @param array<string, PropSpec> $props  attribute => spec
     * @param array<string, string>   $events attribute (onClick) => Slint callback (clicked)
     * @param array<string, string>   $eventValues attribute => type of the value its callback passes
     *                                             (onSelect: the clicked item, a string), handed to the handler
     * @param array<string, string>   $enables attribute => a bool property set to true when the template
     *                                         uses the event (a Textarea sends on Return only with onSubmit)
     */
    public function __construct(
        public readonly string $component,
        public readonly array $props,
        public readonly array $events,
        public readonly bool $children,
        public readonly array $eventValues = [],
        public readonly array $enables = [],
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
        $events = [];
        $values = [];
        $enables = [];
        foreach ($raw['events'] ?? [] as $attribute => $event) {
            // onClick: clicked; onSelect: { callback: selected, value: string } for one that passes a value;
            // onSubmit: { callback: submitted, enables: submit-on-return } for one that switches a behaviour on
            if (is_array($event)) {
                $events[(string) $attribute] = (string) ($event['callback'] ?? throw new RageKitException("$where.events.$attribute: needs a callback"));
                if (isset($event['enables'])) {
                    $enables[(string) $attribute] = (string) $event['enables'];
                }
                if (isset($event['value']) || !isset($event['enables'])) {
                    $values[(string) $attribute] = (string) ($event['value'] ?? 'string');
                }
            } else {
                $events[(string) $attribute] = (string) $event;
            }
        }
        return new self((string) $raw['component'], $props, $events, (bool) ($raw['children'] ?? false), $values, $enables);
    }
}
