<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\PropSpec;
use RageKit\Cli\Templates\TemplateException;

/**
 * What goes into attributes: literal and bound values ({name}), two-way bindings, images,
 * constants, and the handlers events run. Collects the images templates embed.
 */
final class ValueCompiler
{
    /** PHP type => Slint type of values a template can show. */
    public const TYPES = ['string' => 'string', 'int' => 'int', 'float' => 'float', 'bool' => 'bool', 'list' => '[string]'];

    /** @var list<string> absolute paths of the images templates embed */
    private array $images = [];

    /** @param string $projectRoot what image paths in templates are relative to */
    public function __construct(private readonly string $projectRoot = '')
    {
    }

    /** @return list<string> */
    public function images(): array
    {
        return array_values(array_unique($this->images));
    }

    public function twoWayBinding(string $raw, PropSpec $prop, Scope $scope, Element $element, string $attribute): ?Binding
    {
        if (!Expression::hasRefs($raw)) {
            return null;
        }
        if ($scope->hasItems()) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs can't be repeated with each= yet");
        }
        $parts = Expression::parts($raw);
        $name = $parts[0][1];
        if (count($parts) !== 1 || $scope->model === null || $scope->item($name) !== null || !isset($scope->class->props[$name])) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs must bind a property of the screen");
        }
        $type = $this->valueType($scope->class->props[$name], $element, "property \$$name");
        if ($type !== $prop->type) {
            throw TemplateException::at($element, "$attribute=\"$raw\": expected " . (in_array($prop->type[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a') . " {$prop->type} property, \$$name is $type");
        }
        $binding = $scope->model->bind('property', $name, $type);
        $binding->twoWay = true;
        return $binding;
    }

    /** @param list<string>|null $enum */
    public function value(string $raw, string $type, ?array $enum, Scope $scope, Element $element, string $attribute): string
    {
        $parts = Expression::parts($raw);
        if ($enum !== null) {
            if (Expression::hasRefs($raw) || !in_array($raw, $enum, true)) {
                throw TemplateException::at($element, "$attribute=\"$raw\" must be one of: " . implode(', ', $enum));
            }
            return Expression::slintString($raw);
        }
        if ($type === 'image') {
            return $this->image($raw, $element, $attribute);
        }
        if ($type === 'string') {
            $out = '';
            foreach ($parts as [$kind, $text]) {
                if ($kind === 'text') {
                    $out .= Expression::escape($text);
                    continue;
                }
                $ref = $this->resolve($text, $scope, $element);
                if ($ref->type === 'list') {
                    throw TemplateException::at($element, "$attribute=\"$raw\": '$text' is a list; show its items with each=\"…\"");
                }
                $out .= $ref->type === 'bool'
                    ? '\{(' . $ref->slint . ' ? "true" : "false")}'
                    : '\{' . $ref->slint . '}';
            }
            return '"' . $out . '"';
        }
        if (count($parts) === 1 && $parts[0][0] === 'ref') {
            $ref = $this->resolve($parts[0][1], $scope, $element);
            if ($ref->type === $type || ($type === 'float' && $ref->type === 'int')) {
                return $ref->slint;
            }
            throw TemplateException::at($element, "$attribute=\"$raw\": expected $type, '{$parts[0][1]}' is {$ref->type}");
        }
        return match ($type) {
            'bool' => in_array($raw, ['true', 'false'], true) ? $raw : throw TemplateException::at($element, "$attribute=\"$raw\" must be true, false or a {bool}"),
            'int' => preg_match('/^-?\d+$/', $raw) === 1 ? $raw : throw TemplateException::at($element, "$attribute=\"$raw\" must be a whole number or an {int}"),
            'float' => is_numeric($raw) ? $raw : throw TemplateException::at($element, "$attribute=\"$raw\" must be a number or a {float}"),
            default => throw TemplateException::at($element, "$attribute=\"$raw\": a list needs a single {name}"),
        };
    }

    /** A literal image path (project-relative or absolute), embedded at build time via @image-url. */
    private function image(string $raw, Element $element, string $attribute): string
    {
        if (Expression::hasRefs($raw)) {
            throw TemplateException::at($element, "$attribute=\"$raw\": image props take a file path; binding images to PHP values isn't supported yet");
        }
        $absolute = preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $raw) === 1;
        $path = realpath($absolute ? $raw : rtrim($this->projectRoot, '/\\') . '/' . $raw);
        if ($path === false || !is_file($path)) {
            throw TemplateException::at($element, "$attribute=\"$raw\": file not found");
        }
        $this->images[] = $path;
        return '@image-url(' . Expression::slintString(str_replace('\\', '/', $path)) . ')';
    }

    public function handler(string $raw, Scope $scope, Element $element): string
    {
        if ($scope->model === null) {
            throw TemplateException::at($element, 'child components can\'t handle events yet; put interactive elements in the screen\'s template');
        }
        if (preg_match('/^\s*([A-Za-z_]\w*)\s*(?:\((.*)\))?\s*$/', $raw, $m) !== 1) {
            throw TemplateException::at($element, "\"$raw\" must look like method, method(item) or method('text')");
        }
        $method = $m[1];
        $params = $scope->class->handlers[$method]
            ?? throw TemplateException::at($element, "'$method' is not a public method of {$scope->class->short}");
        foreach ($params as $i => $type) {
            if (!in_array(ltrim($type, '?'), ['string', 'int', 'float', 'bool', 'mixed'], true)) {
                throw TemplateException::at($element, "$method(): parameter " . ($i + 1) . " is a $type; handler parameters must be string, int, float or bool");
            }
        }
        $args = [];
        $item = '""';
        $rawArgs = trim($m[2] ?? '');
        foreach ($rawArgs === '' ? [] : array_map('trim', explode(',', $rawArgs)) as $arg) {
            if (preg_match("/^'(.*)'$/", $arg, $literal) === 1) {
                $args[] = ['literal', $literal[1]];
                continue;
            }
            $ref = $scope->item($arg)
                ?? throw TemplateException::at($element, "argument '$arg' of $method(): pass an each item or a 'quoted text'");
            if ($ref->type !== 'string' || $item !== '""') {
                throw TemplateException::at($element, "$method(): only one each item (a text value) can be passed");
            }
            $item = $arg;
            $args[] = ['arg', $arg];
        }
        $required = $scope->class->requiredParams[$method] ?? count($params);
        if (count($args) < $required || count($args) > count($params)) {
            $takes = $required === count($params) ? (string) $required : "$required to " . count($params);
            throw TemplateException::at($element, "$method() takes $takes argument(s), the template passes " . count($args));
        }
        $action = $scope->model->action('call', $method, $args);
        return "root.action({$action->id}, $item);";
    }

    public function resolve(string $name, Scope $scope, Element $at): Ref
    {
        $item = $scope->item($name);
        if ($item !== null) {
            return $item;
        }
        $class = $scope->class;
        if (array_key_exists($name, $class->constants)) {
            return $this->constant($class->constants[$name], $name, $at);
        }
        if (isset($class->props[$name])) {
            $type = $this->valueType($class->props[$name], $at, "property \$$name");
            $slint = $scope->model === null ? "root.rk-$name" : 'root.' . $scope->model->bind('property', $name, $type)->slintName();
            return new Ref($type, $slint, $type === 'list' ? 'string' : null);
        }
        if (isset($class->computed[$name]) && in_array($class->computed[$name], array_keys(self::TYPES), true)) {
            if ($scope->model === null) {
                throw TemplateException::at($at, "'$name' is a method; child components can only show their properties (computed values in children aren't supported yet)");
            }
            $type = $class->computed[$name];
            return new Ref($type, 'root.' . $scope->model->bind('method', $name, $type)->slintName(), $type === 'list' ? 'string' : null);
        }
        if (isset($class->computed[$name])) {
            $type = $class->computed[$name];
            throw TemplateException::at($at, $type === 'mixed'
                ? "'$name()' has no return type; give it one (string, int, float, bool or array) to show it in a template"
                : "'$name()' returns $type; templates can show string, int, float, bool or array (a list of strings)");
        }
        if (isset($class->handlers[$name])) {
            throw TemplateException::at($at, "'$name()' takes arguments; templates can only show methods without required parameters");
        }
        throw TemplateException::at($at, "'$name' is not a property, method or constant of {$class->short}");
    }

    private function constant(mixed $value, string $name, Element $at): Ref
    {
        if (is_string($value)) {
            return new Ref('string', Expression::slintString($value));
        }
        if (is_bool($value)) {
            return new Ref('bool', $value ? 'true' : 'false');
        }
        if (is_int($value)) {
            return new Ref('int', (string) $value);
        }
        if (is_float($value)) {
            return new Ref('float', var_export($value, true));
        }
        if (is_array($value) && array_is_list($value)) {
            $scalars = array_filter($value, 'is_scalar');
            if (count($scalars) === count($value)) {
                return new Ref('list', '[' . implode(', ', array_map(fn($v) => Expression::slintString((string) $v), $value)) . ']', 'string');
            }
            $rows = [];
            foreach ($value as $row) {
                if (!is_array($row) || count(array_filter($row, 'is_scalar')) !== count($row)) {
                    throw TemplateException::at($at, "constant $name: only lists of values or lists of lists of values are supported");
                }
                $rows[] = '[' . implode(', ', array_map(fn($v) => Expression::slintString((string) $v), $row)) . ']';
            }
            return new Ref('list', '[' . implode(', ', $rows) . ']', 'list');
        }
        throw TemplateException::at($at, "constant $name has a type templates can't show");
    }

    public function valueType(string $type, Element $at, string $what): string
    {
        if (!isset(self::TYPES[$type])) {
            throw TemplateException::at($at, "$what has type $type; templates support string, int, float, bool and array (not nullable)");
        }
        return $type;
    }
}
