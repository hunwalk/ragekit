<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Kits\ElementSpec;
use RageKit\Cli\Kits\Kit;
use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\Errors\NoChildrenAllowed;
use RageKit\Cli\Templates\TemplateException;

/**
 * Any element a kit provides (kit.yml `elements:`): core elements (<Button>, <Entry>, …) from the
 * active kit, else Material; prefixed ones (<ui:Card>) from the kit their xmlns names. The
 * dispatcher's fallback, so it claims no tags of its own.
 */
final class KitElementCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return [];
    }

    /** Elements every kit provides with the same attributes (docs/kits.md, "The core element contract"). */
    public const CORE = [
        'Label', 'Button', 'Entry', 'Checkbox', 'Slider', 'Select', 'Image', 'Progress', 'Card', 'Separator',
        'Switch', 'Textarea', 'RadioGroup', 'Toggle', 'Badge', 'Avatar', 'Alert', 'Skeleton', 'Spinner',
        'Sidebar', 'NavList', 'Breadcrumb', 'Pagination', 'Text', 'Dialog', 'DropdownMenu',
    ];

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        [$kit, $spec] = $this->elementSpec($element);
        $hasImage = ($spec->props['source'] ?? null)?->type === 'image';
        $this->context->checkAttributes($element, [...array_keys($spec->props), ...array_keys($spec->events), 'grow', 'when', 'each', ...($hasImage ? ['fallback'] : [])], styled: true);
        if ($element->children !== [] && !$spec->children) {
            throw new NoChildrenAllowed($element);
        }
        $this->context->useKit($kit, $spec->component);
        $lines = [];
        foreach ($spec->props as $name => $prop) {
            if (!isset($element->attributes[$name])) {
                continue;
            }
            $raw = $element->attributes[$name];
            $binding = $prop->twoWay !== null ? $this->context->values->twoWayBinding($raw, $prop, $scope, $element, $name) : null;
            if ($binding !== null) {
                $action = $scope->model?->action('set', $binding->name, [], $binding, $binding->owner, null, $scope->inWindow());
                $value = match ($prop->type) {
                    'bool' => 'value ? "1" : "0"',
                    'string' => 'value',
                    default => '"\{value}"',
                };
                $lines[] = "{$prop->property} <=> root.{$binding->slintName()};";
                $lines[] = "{$prop->twoWay}(value) => { root.action({$action?->id}, $value); }";
                continue;
            }
            $value = $this->context->values->value($raw, $prop->type, $prop->enum, $scope, $element, $name);
            if ($prop->type === 'image' && isset($element->attributes['fallback'])) {
                // Shown while a bound image is empty: loading, failed, or ''.
                $fallback = $this->context->values->staticImage($element->attributes['fallback'], $element, 'fallback');
                if (!str_starts_with($value, '@image-url(')) {
                    $value = "$value.width > 0 ? $value : $fallback";
                }
            }
            $lines[] = "{$prop->property}: $value;";
        }
        foreach ($spec->events as $attribute => $callback) {
            if (!isset($element->attributes[$attribute])) {
                continue;
            }
            if (isset($spec->enables[$attribute])) {
                $lines[] = "{$spec->enables[$attribute]}: true;";
            }
            $lines[] = isset($spec->eventValues[$attribute])
                ? $this->valueEvent($element, $attribute, $callback, $spec->eventValues[$attribute], $scope)
                : "$callback => { " . $this->context->values->handler($element->attributes[$attribute], $scope, $element) . ' }';
        }
        $style = $this->context->styleOf($element, $scope, $element->namespace === null && in_array($element->tag, ['Label', 'Text'], true) ? 'label' : 'kit', array_keys($spec->props));
        $lines = [...$lines, ...CompileContext::styleLines($style, $style->box), ...CompileContext::styleLines($style, $style->text)];
        $lines[] = $this->context->stretch($element, $axis);
        $children = '';
        foreach ($element->children as $child) {
            $children .= $this->context->element($child, $scope, 'v');
        }
        return $this->context->withMargin(Code::block($spec->component, $lines, $children), $style, $element, $axis);
    }

    /** onSelect="goTo": an event whose callback passes a value (the clicked item) to the handler. */
    private function valueEvent(Element $element, string $attribute, string $callback, string $type, Scope $scope): string
    {
        $method = trim($element->attributes[$attribute]);
        if (preg_match('/^[A-Za-z_]\w*$/', $method) !== 1) {
            throw TemplateException::at($element, "$attribute passes the selected item to the method: write $attribute=\"" . preg_replace('/\W.*$/s', '', ltrim($method, '$')) . '"');
        }
        $params = $scope->class->handlers[$method]
            ?? throw TemplateException::at($element, "'$method' is not a public method of {$scope->class->short}");
        if (count($params) !== 1) {
            throw TemplateException::at($element, "$method() must take exactly one parameter: the selected item");
        }
        $param = ltrim($params[0], '?');
        if ($param !== $type && !($param === 'float' && $type === 'int') && !($param === 'string' && $type === 'int')) {
            throw TemplateException::at($element, "$attribute gives " . ($type === 'int' ? 'an' : 'a') . " $type; $method()'s parameter is " . ($param === 'int' ? 'an' : 'a') . " $param");
        }
        $action = $scope->model?->action('call', $method, [['arg', 'value']], null, $scope->owner, $params)
            ?? throw TemplateException::at($element, 'child components can\'t handle events yet; put interactive elements in the screen\'s template');
        return "$callback(value) => { root.action({$action->id}, " . ($type === 'string' ? 'value' : '"\\{value}"') . '); }';
    }

    /** @return array{0: Kit, 1: ElementSpec} */
    private function elementSpec(Element $element): array
    {
        if ($element->namespace !== null) {
            $kit = $this->context->kits->find($element->namespace)
                ?? throw TemplateException::at($element, "xmlns \"{$element->namespace}\" is not an installed kit (installed: " . implode(', ', $this->context->kits->names()) . ')');
            $spec = $kit->elements[$element->tag]
                ?? throw TemplateException::at($element, "kit {$kit->name} has no element <{$element->tag}>");
            return [$kit, $spec];
        }
        if (in_array($element->tag, self::CORE, true)) {
            $found = $this->context->coreSpec($element->tag);
            if ($found !== null) {
                return $found;
            }
        }
        throw TemplateException::at($element, "unknown element <{$element->tag}> (core: View, VStack, HStack, ScrollView, " . implode(', ', self::CORE)
            . '; components: ' . (implode(', ', array_keys($this->context->children)) ?: 'none') . '; kit elements need their prefix, e.g. <ui:Card>)');
    }
}
