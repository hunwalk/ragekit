<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\RageKitException;

/** One attribute a kit element accepts (kit.yml `props:` entry). */
final class PropSpec
{
    public const TYPES = ['string', 'int', 'float', 'bool', 'list', 'image'];

    /**
     * @param string            $property the Slint property it sets (defaults to the attribute name)
     * @param list<string>|null $enum     allowed literal values
     * @param string|null       $twoWay   Slint callback fired with the new value when the user edits it
     */
    public function __construct(
        public readonly string $type,
        public readonly string $property,
        public readonly ?array $enum = null,
        public readonly ?string $twoWay = null,
    ) {
    }

    /** `text: string` or `value: { type: string, property: text, twoWay: edited, enum: [...] }` */
    public static function fromYaml(string $name, mixed $raw, string $where): self
    {
        if (is_string($raw)) {
            $raw = ['type' => $raw];
        }
        if (!is_array($raw)) {
            throw new RageKitException("$where: expected a type name or a map");
        }
        $type = (string) ($raw['type'] ?? 'string');
        if (!in_array($type, self::TYPES, true)) {
            throw new RageKitException("$where: unknown type '$type' (use " . implode(', ', self::TYPES) . ')');
        }
        if ($type === 'image' && (isset($raw['twoWay']) || isset($raw['enum']))) {
            throw new RageKitException("$where: image props can't be two-way or enums");
        }
        return new self(
            $type,
            (string) ($raw['property'] ?? $name),
            isset($raw['enum']) ? array_map('strval', (array) $raw['enum']) : null,
            isset($raw['twoWay']) ? (string) $raw['twoWay'] : null,
        );
    }
}
