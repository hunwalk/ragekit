<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * The rows of collections holding one item class: a Slint struct ($struct) with a field per member
 * the app's templates read, in the order they were first used. Shared by every collection of that
 * item class, so its fields are only final once every template is compiled.
 */
final class RowType
{
    /** Member types a row can show. */
    private const SCALARS = ['string', 'int', 'float', 'bool'];

    /** @var array<string, RowField> by member (+ '()' for a method, ':image' for an image field) */
    private array $fields = [];

    /** @param ClassInfo $collection a collection of the item class (its props/computed are the item's members) */
    public function __construct(
        public readonly ClassInfo $collection,
        public readonly string $item,
        public readonly string $struct,
    ) {
    }

    /** The item class's short name. */
    public function itemShort(): string
    {
        $at = strrpos($this->item, '\\');
        return $at === false ? $this->item : substr($this->item, $at + 1);
    }

    /**
     * The field for $var->member (or ->member() when $call), checked against the item class;
     * added on first use. $image: the member is shown as an image (a string member).
     */
    public function field(string $var, string $member, bool $call, bool $image, Element $at): RowField
    {
        $key = $member . ($call ? '()' : '') . ($image ? ':image' : '');
        $label = "\$$var->$member" . ($call ? '()' : '');
        $props = $this->collection->props;
        $computed = $this->collection->computed;
        if (!isset($props[$member]) && !isset($computed[$member])) {
            $members = [...array_keys($props), ...array_map(fn(string $m) => "$m()", array_keys($computed))];
            throw TemplateException::at($at, "$label: {$this->itemShort()} has no public property or method '$member' (it has: " . implode(', ', $members) . ')');
        }
        if ($call && !isset($computed[$member])) {
            throw TemplateException::at($at, "\$$var->$member is a property: write \$$var->$member");
        }
        if (!$call && !isset($props[$member])) {
            throw TemplateException::at($at, "\$$var->$member is a method: write \$$var->$member()");
        }
        $type = $call ? $computed[$member] : $props[$member];
        if (!in_array($type, self::SCALARS, true)) {
            throw TemplateException::at($at, match (true) {
                $type === 'list' => "$label is an array; rows can show string, int, float and bool members",
                str_starts_with($type, '?') && in_array(substr($type, 1), self::SCALARS, true) => "$label is $type; rows can't show nullable members",
                $call => "$label returns $type; rows can show string, int, float and bool members",
                default => "$label is $type; rows can show string, int, float and bool members",
            });
        }
        if ($image && $type !== 'string') {
            throw TemplateException::at($at, "$label is " . ($type === 'int' ? 'an' : 'a') . " $type; image sources need a string");
        }
        return $this->fields[$key] ??= new RowField($member, $call ? 'method' : 'property', $image ? 'image' : $type, 'f' . count($this->fields));
    }

    /** @return list<RowField> */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    /** The struct's Slint declaration. */
    public function slint(): string
    {
        $lines = array_map(fn(RowField $f) => "    {$f->name}: {$f->type},", $this->fields());
        return "export struct {$this->struct} {\n" . implode("\n", $lines) . ($lines === [] ? '' : "\n") . '}';
    }
}
