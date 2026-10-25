<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Rageblade\Node;
use RageKit\Cli\Rageblade\Parser;
use RageKit\Cli\Templates\AssetRegistry;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Kits\PropSpec;
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

    private ?ExpressionCompiler $expressions = null;

    /** The app's RootStore subclass (null: none). */
    private ?ClassInfo $root = null;

    /** @var array<string, ClassInfo> store name ($store->name) => its Store class */
    private array $stores = [];

    private ?AssetRegistry $assets = null;

    private RowTypes $rows;

    /** @param string $projectRoot what image paths in templates are relative to */
    public function __construct(private readonly string $projectRoot = '')
    {
        $this->rows = new RowTypes();
    }

    /** The app's Collection subclasses, for @foreach over them. */
    public function useCollections(RowTypes $rows): void
    {
        $this->rows = $rows;
    }

    /** The rows of $type when it is a Collection subclass, else null. */
    public function rowsOf(string $type, Element $at): ?RowType
    {
        return $this->rows->for($type, $at);
    }

    /** A collection shown in a template: a binding of type 'rows'. */
    private function rowsRef(string $source, string $name, string $owner, RowType $row, Scope $scope, Element $at): Ref
    {
        if ($scope->model === null) {
            throw TemplateException::at($at, "child components can't show collections yet; loop over them in the screen's template");
        }
        $binding = $scope->model->bind($source, $name, 'rows', $owner, $row);
        return new Ref('rows', 'root.' . $binding->slintName(), row: $row, origin: [$source, $name, $owner]);
    }

    /**
     * The app's root store and its stores, for $store->x->y.
     *
     * @param array<string, ClassInfo> $stores Store classes by class name
     */
    public function useStores(?ClassInfo $root, array $stores): void
    {
        $this->root = $root;
        $this->stores = [];
        foreach ($root === null ? [] : $root->props as $name => $type) {
            $class = $stores[ltrim($type, '?')] ?? null;
            if ($class !== null) {
                $this->stores[$name] = $class;
            }
        }
    }

    /**
     * $store->$store->$member shown in a template: a window-level binding (owner 'store', name
     * "session.userName"), so every window showing it follows a change made anywhere.
     */
    public function storeValue(string $store, string $member, bool $call, Scope $scope, Element $at): Ref
    {
        $class = $this->store($store, $scope, $at);
        $path = "\$store->$store->$member";
        $isMethod = isset($class->handlers[$member]);
        if ($call && !$isMethod && isset($class->props[$member])) {
            throw TemplateException::at($at, "$path is a property: write $path");
        }
        if (!$call && $isMethod) {
            throw TemplateException::at($at, "$path is a method: write $path()");
        }
        $row = $this->rowsOf((string) ($call ? ($class->computed[$member] ?? '') : ($class->props[$member] ?? '')), $at);
        if ($row !== null) {
            return $this->rowsRef($call ? 'method' : 'property', "$store.$member", 'store', $row, $scope, $at);
        }
        if (!$call && isset($class->props[$member])) {
            $type = $this->valueType($class->props[$member], $at, $path);
            return new Ref($type, 'root.' . $scope->model->bind('property', "$store.$member", $type, 'store')->slintName(), $type === 'list' ? 'string' : null, origin: ['property', "$store.$member", 'store']);
        }
        if (!$isMethod) {
            throw TemplateException::at($at, "$path: '$member' is not a property or method of {$class->short} (it has: " . self::members($class) . ')');
        }
        $type = $class->computed[$member] ?? null;
        if ($type === null) {
            throw TemplateException::at($at, "$path() takes arguments; templates can only show methods without required parameters");
        }
        if (!isset(self::TYPES[$type])) {
            throw TemplateException::at($at, $type === 'mixed'
                ? "$path() has no return type; give it one (string, int, float, bool or array) to show it in a template"
                : "$path() returns $type; templates can show string, int, float, bool or array (a list of strings)");
        }
        return new Ref($type, 'root.' . $scope->model->bind('method', "$store.$member", $type, 'store')->slintName(), $type === 'list' ? 'string' : null, origin: ['method', "$store.$member", 'store']);
    }

    /** The Store class behind $store->$name, checked: templates of screens and windows only, the root and the store exist. */
    private function store(string $name, Scope $scope, Element $at): ClassInfo
    {
        if ($scope->model === null) {
            throw TemplateException::at($at, "child components can't read \$store yet; pass the value as a property: <{$scope->class->short} name=\"{{ \$store->… }}\" />");
        }
        if ($this->root === null) {
            throw TemplateException::at($at, "\$store needs the app's RootStore: declare a class that extends RageKit\\RootStore with your stores as typed public properties");
        }
        return $this->stores[$name]
            ?? throw TemplateException::at($at, "\$store->$name: {$this->root->short} has no store '$name' (its stores: " . implode(', ', array_keys($this->stores)) . ')');
    }

    private static function members(ClassInfo $class): string
    {
        return implode(', ', [...array_keys($class->props), ...array_map(fn(string $m) => "$m()", array_keys($class->handlers))]);
    }

    /** The app's registered images, for $assets->group->name. */
    public function useAssets(AssetRegistry $assets): void
    {
        $this->assets = $assets;
    }

    /** Records an image file embedded by generated code outside templates (RkAssets). */
    public function embed(string $path): void
    {
        $this->images[] = $path;
    }

    /** @return list<string> */
    public function images(): array
    {
        return array_values(array_unique($this->images));
    }

    public function twoWayBinding(string $raw, PropSpec $prop, Scope $scope, Element $element, string $attribute): ?Binding
    {
        if (!TextAttribute::hasRefs($raw)) {
            return null;
        }
        if (str_contains($raw, '{{')) {
            // value="{{ $name }}" is the Rageblade spelling of value="{name}"; any other expression
            // is shown one-way (the input displays it; edits don't reach PHP).
            if (preg_match('/^\s*\{\{\s*\$store->([A-Za-z_]\w*)->([A-Za-z_]\w*)\s*\}\}\s*$/', $raw, $m) === 1) {
                return $this->twoWayStore($m[1], $m[2], $prop, $scope, $element, $attribute, $raw);
            }
            if (preg_match('/^\s*\{\{\s*\$([A-Za-z_]\w*)\s*\}\}\s*$/', $raw, $m) !== 1) {
                return null;
            }
            $raw = '{' . $m[1] . '}';
        }
        if ($scope->hasItems()) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs can't be repeated with each= yet");
        }
        $parts = TextAttribute::parts($raw);
        $name = $parts[0][1];
        if (str_starts_with($name, 'context.')) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs in a window's template must bind the window's own properties; pass values to the screen from a handler through \$this->context");
        }
        if (count($parts) !== 1 || $scope->model === null || $scope->item($name) !== null || !isset($scope->class->props[$name])) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs must bind a property of the " . ($scope->inWindow() ? 'window' : 'screen'));
        }
        $type = $this->valueType($scope->class->props[$name], $element, "property \$$name");
        if ($type !== $prop->type) {
            throw TemplateException::at($element, "$attribute=\"$raw\": expected " . (in_array($prop->type[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a') . " {$prop->type} property, \$$name is $type");
        }
        $binding = $scope->model->bind('property', $name, $type, $scope->owner);
        $binding->twoWay = true;
        return $binding;
    }

    /** checked="{{ $store->settings->darkMode }}": an input editing a store property directly. */
    private function twoWayStore(string $store, string $member, PropSpec $prop, Scope $scope, Element $element, string $attribute, string $raw): Binding
    {
        if ($scope->hasItems()) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs can't be repeated with each= yet");
        }
        $class = $this->store($store, $scope, $element);
        if (!isset($class->props[$member])) {
            throw TemplateException::at($element, "$attribute=\"$raw\": two-way inputs must bind a property; '$member' is not a property of {$class->short} (it has: " . self::members($class) . ')');
        }
        $type = $this->valueType($class->props[$member], $element, "\$store->$store->$member");
        if ($type !== $prop->type) {
            throw TemplateException::at($element, "$attribute=\"$raw\": expected " . (in_array($prop->type[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a') . " {$prop->type} property, \$store->$store->$member is $type");
        }
        $binding = $scope->model->bind('property', "$store.$member", $type, 'store');
        $binding->twoWay = true;
        return $binding;
    }

    /** @param list<string>|null $enum */
    public function value(string $raw, string $type, ?array $enum, Scope $scope, Element $element, string $attribute): string
    {
        $parts = TextAttribute::parts($raw);
        if ($enum !== null) {
            if (TextAttribute::hasRefs($raw) || !in_array($raw, $enum, true)) {
                throw TemplateException::at($element, "$attribute=\"$raw\" must be one of: " . implode(', ', $enum));
            }
            return TextAttribute::slintString($raw);
        }
        if ($type === 'image') {
            return $this->image($raw, $scope, $element, $attribute);
        }
        if (str_contains($raw, '{{')) {
            return $this->rageblade($raw, $type, $scope, $element, $attribute);
        }
        if ($type === 'string') {
            $out = '';
            foreach ($parts as [$kind, $text]) {
                if ($kind === 'text') {
                    $out .= TextAttribute::escape($text);
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

    private function expressions(): ExpressionCompiler
    {
        return $this->expressions ??= new ExpressionCompiler($this);
    }

    /** An attribute with {{ expressions }}: text for strings, else one expression of the attribute's type. */
    private function rageblade(string $raw, string $type, Scope $scope, Element $element, string $attribute): string
    {
        if ($type === 'string') {
            return $this->expressions()->compileText($raw, $scope, $element);
        }
        if (preg_match('/^\s*\{\{(.*)\}\}\s*$/s', $raw, $m) !== 1 || str_contains($m[1], '}}')) {
            throw TemplateException::at($element, "$attribute=\"$raw\": a $type attribute takes one {{ expression }} and no other text");
        }
        $ref = $this->expressions()->compile(Parser::parse($m[1], $element->file, $element->line), $scope, $element);
        if ($ref->type === $type || ($type === 'float' && $ref->type === 'int')) {
            return $ref->slint;
        }
        throw TemplateException::at($element, "$attribute=\"$raw\": expected " . ($type === 'int' ? 'an' : 'a') . " $type, the expression is " . ($ref->type === 'int' ? 'an' : 'a') . " {$ref->type}");
    }

    /**
     * An image attribute: a literal path or $assets->group->name (both embedded at build time), or
     * one string property / method / $store value of the screen or window, shown at runtime
     * (binding type image; RageKit\Slint\Images resolves the value).
     */
    private function image(string $raw, Scope $scope, Element $element, string $attribute): string
    {
        $node = $this->imageNode($raw, $element, $attribute);
        if ($node === null || $node->kind === 'assets') {
            return $this->staticImage($raw, $element, $attribute);
        }
        if ($node->kind === 'member') {
            // A @foreach item's member: an image field of its row.
            [$var, $member, $call] = $node->value;
            $item = $scope->item($var);
            if ($item === null || $item->row === null) {
                throw TemplateException::at($element, "$attribute=\"$raw\": \$$var is not an object; ->member works on @foreach items of a collection");
            }
            return "$var." . $item->row->field($var, $member, $call, true, $element)->name;
        }
        if (!in_array($node->kind, ['var', 'call', 'store'], true) || ($node->kind !== 'var' && $node->children !== [])) {
            throw TemplateException::at($element, "$attribute=\"$raw\": image sources take one property, method or \$store value; for anything else add a method");
        }
        $item = $node->kind === 'var' ? $scope->item((string) $node->value) : null;
        if ($item !== null) {
            // An item of a bound list of strings: the same list again, shown as images, by index.
            if ($item->origin === null || $item->index === null || $scope->model === null) {
                throw TemplateException::at($element, "$attribute=\"$raw\": images in a loop over a constant aren't supported; use a property or method");
            }
            [$source, $name, $owner] = $item->origin;
            return 'root.' . $scope->model->bind($source, $name, 'images', $owner)->slintName() . "[{$item->index}]";
        }
        if ($scope->model === null) {
            throw TemplateException::at($element, "$attribute=\"$raw\": child components can't show images bound to PHP values yet");
        }
        [$source, $name, $owner, $type, $label] = $this->imageSource($node, $scope, $element);
        if ($type !== 'string') {
            throw TemplateException::at($element, "$attribute=\"$raw\": expected a string, '$label' is " . self::article($type));
        }
        return 'root.' . $scope->model->bind($source, $name, 'image', $owner)->slintName();
    }

    /**
     * A literal path or $assets->group->name, embedded via @image-url (also what fallback= takes).
     */
    public function staticImage(string $raw, Element $element, string $attribute): string
    {
        $node = $this->imageNode($raw, $element, $attribute);
        if ($node !== null && $node->kind !== 'assets') {
            throw TemplateException::at($element, "$attribute=\"$raw\": $attribute takes a file path or \$assets->…, not a bound value");
        }
        if ($node !== null) {
            [$group, $name] = $node->value;
            return $this->embedded($this->asset($group, $name, $element));
        }
        if (TextAttribute::hasRefs($raw)) {
            throw TemplateException::at($element, "$attribute=\"$raw\": write a bound image as {{ \$name }}");
        }
        $absolute = preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $raw) === 1;
        $path = realpath($absolute ? $raw : rtrim($this->projectRoot, '/\\') . '/' . $raw);
        if ($path === false || !is_file($path)) {
            throw TemplateException::at($element, "$attribute=\"$raw\": file not found");
        }
        return $this->embedded($path);
    }

    private function embedded(string $path): string
    {
        $this->images[] = $path;
        return '@image-url(' . TextAttribute::slintString(str_replace('\\', '/', $path)) . ')';
    }

    /** The one {{ expression }} of an image attribute, or null for a literal path. */
    private function imageNode(string $raw, Element $element, string $attribute): ?Node
    {
        if (!str_contains($raw, '{{')) {
            return null;
        }
        if (preg_match('/^\s*\{\{(.*)\}\}\s*$/s', $raw, $m) !== 1 || str_contains($m[1], '}}')) {
            throw TemplateException::at($element, "$attribute=\"$raw\": an image takes one {{ expression }} and no other text");
        }
        return Parser::parse($m[1], $element->file, $element->line);
    }

    private function asset(string $group, string $name, Element $at): string
    {
        $assets = $this->assets ?? AssetRegistry::empty();
        if ($assets->groups() === []) {
            throw TemplateException::at($at, "\$assets needs images registered by your App: protected function assets(): array { return ['$group' => Images::class]; }");
        }
        if (!in_array($group, $assets->groups(), true)) {
            throw TemplateException::at($at, "\$assets->$group: no asset group '$group' (groups: " . implode(', ', $assets->groups()) . ')');
        }
        return $assets->find($group, $name)
            ?? throw TemplateException::at($at, "\$assets->$group->$name: no image '$name' ($group has: " . implode(', ', $assets->names($group)) . ')');
    }

    /**
     * What a bound image source reads: [binding source, name, owner, PHP type, how the template wrote it].
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function imageSource(Node $node, Scope $scope, Element $at): array
    {
        if ($node->kind === 'store') {
            [$store, $member, $call] = $node->value;
            $class = $this->store($store, $scope, $at);
            $label = "\$store->$store->$member" . ($call ? '()' : '');
            $type = $call ? ($class->computed[$member] ?? null) : ($class->props[$member] ?? null);
            if ($type === null) {
                throw TemplateException::at($at, "$label: '$member' is not a " . ($call ? 'method without parameters' : 'property') . " of {$class->short} (it has: " . self::members($class) . ')');
            }
            return [$call ? 'method' : 'property', "$store.$member", 'store', $type, $label];
        }
        $name = (string) $node->value;
        $class = $scope->class;
        if ($node->kind === 'call') {
            $type = $class->computed[$name] ?? throw TemplateException::at($at, "'$name' is not a public method without parameters of {$class->short}");
            return ['method', $name, $scope->owner, $type, "$name()"];
        }
        $type = $class->props[$name] ?? throw TemplateException::at($at, "'\$$name' is not a property of {$class->short}");
        return ['property', $name, $scope->owner, $type, "\$$name"];
    }

    public function handler(string $raw, Scope $scope, Element $element): string
    {
        if ($scope->model === null) {
            throw TemplateException::at($element, 'child components can\'t handle events yet; put interactive elements in the screen\'s template');
        }
        if (str_contains($raw, '$')) {
            return $this->ragebladeHandler($raw, $scope, $element);
        }
        if (preg_match('/^\s*((?:context\.)?[A-Za-z_]\w*)\s*(?:\((.*)\))?\s*$/', $raw, $m) !== 1) {
            throw TemplateException::at($element, "\"$raw\" must look like method, method(item) or method('text')");
        }
        $method = $m[1];
        $owner = $scope->owner;
        if (str_starts_with($method, 'context.')) {
            $method = substr($method, 8);
            $member = $this->contextMember($method, $scope, $element);
            $params = $member['params'];
            $required = $member['required'];
            $owner = 'context';
        } else {
            $params = $scope->class->handlers[$method]
                ?? throw TemplateException::at($element, "'$method' is not a public method of {$scope->class->short}");
            $required = $scope->class->requiredParams[$method] ?? count($params);
        }
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
        if (count($args) < $required || count($args) > count($params)) {
            $takes = $required === count($params) ? (string) $required : "$required to " . count($params);
            throw TemplateException::at($element, "$method() takes $takes argument(s), the template passes " . count($args));
        }
        $action = $scope->model->action('call', $method, $args, null, $owner, $params);
        return "root.action({$action->id}, $item);";
    }

    /**
     * onClick="move($i, 1)": a method call whose arguments are expressions. Literal arguments are
     * converted at build time; the others are evaluated in Slint and sent as text, separated by
     * U+001F when there are several.
     */
    private function ragebladeHandler(string $raw, Scope $scope, Element $element): string
    {
        $node = Parser::parse($raw, $element->file, $element->line);
        if ($node->kind === 'call') {
            $method = (string) $node->value;
            $params = $scope->class->handlers[$method]
                ?? throw TemplateException::at($element, "'$method' is not a public method of {$scope->class->short}");
            $required = $scope->class->requiredParams[$method] ?? count($params);
            $owner = $scope->owner;
        } elseif ($node->kind === 'context') {
            $method = (string) $node->value;
            $member = $this->contextMember($method, $scope, $element);
            $params = $member['params'];
            $required = $member['required'];
            $owner = 'context';
        } elseif ($node->kind === 'store' && $node->value[2]) {
            [$store, $method] = $node->value;
            $class = $this->store($store, $scope, $element);
            $params = $class->handlers[$method]
                ?? throw TemplateException::at($element, "'$method' is not a public method of {$class->short}");
            $required = $class->requiredParams[$method] ?? count($params);
            $owner = 'store';
        } else {
            throw TemplateException::at($element, "\"$raw\" must call a method, e.g. pick(\$item)");
        }
        if (count($node->children) < $required || count($node->children) > count($params)) {
            $takes = $required === count($params) ? (string) $required : "$required to " . count($params);
            throw TemplateException::at($element, "$method() takes $takes argument(s), the template passes " . count($node->children));
        }
        $args = [];
        $pieces = [];
        foreach ($node->children as $i => $arg) {
            $param = ltrim($params[$i] ?? 'string', '?');
            if (in_array($arg->kind, ['string', 'int', 'float', 'bool'], true)) {
                $this->checkLiteral($method, $i, $arg, $param, $element);
                $args[] = ['literal', $arg->kind === 'bool' ? ($arg->value ? 'true' : 'false') : (string) $arg->value];
                continue;
            }
            $ref = $this->expressions()->compile($arg, $scope, $element);
            $fits = match ($param) {
                'int' => $ref->type === 'int',
                'float' => in_array($ref->type, ['int', 'float'], true),
                'bool' => $ref->type === 'bool',
                default => in_array($ref->type, ['string', 'int', 'float'], true),
            };
            if (!$fits) {
                throw TemplateException::at($element, "$method(): argument " . ($i + 1) . ' is ' . self::article($ref->type) . ', the parameter is ' . self::article($param));
            }
            $args[] = ['expr', count($pieces)];
            $pieces[] = $ref->type === 'bool' ? '\{(' . $ref->slint . ' ? "1" : "0")}' : '\{' . $ref->slint . '}';
        }
        $target = $owner === 'store' ? "{$node->value[0]}.$method" : $method;
        $action = $scope->model->action('call', $target, $args, null, $owner, $params, $scope->inWindow());
        return "root.action({$action->id}, \"" . implode('\u{1f}', $pieces) . '");';
    }

    /** A literal handler argument must fit its parameter: no silent (int) 'abc' = 0. */
    private function checkLiteral(string $method, int $i, Node $arg, string $param, Element $element): void
    {
        $value = $arg->value;
        $fits = match ($param) {
            'int' => $arg->kind === 'int' || ($arg->kind === 'string' && preg_match('/^-?\d+$/', (string) $value) === 1),
            'float' => in_array($arg->kind, ['int', 'float'], true) || ($arg->kind === 'string' && is_numeric($value)),
            'bool' => $arg->kind === 'bool' || ($arg->kind === 'string' && in_array(strtolower((string) $value), ['true', 'false', '1', '0'], true)),
            default => $arg->kind !== 'bool',
        };
        if (!$fits) {
            $shown = match ($arg->kind) {
                'string' => "the text '$value'",
                'bool' => $value ? 'true' : 'false',
                default => (string) $value,
            };
            throw TemplateException::at($element, "$method(): argument " . ($i + 1) . " is $shown, the parameter is " . self::article($param));
        }
    }

    private static function article(string $type): string
    {
        return ($type === 'int' ? 'an ' : 'a ') . $type;
    }

    public function resolve(string $name, Scope $scope, Element $at): Ref
    {
        $item = $scope->item($name);
        if ($item !== null) {
            return $item;
        }
        if (str_starts_with($name, 'context.')) {
            return $this->context(substr($name, 8), $scope, $at);
        }
        $class = $scope->class;
        if (array_key_exists($name, $class->constants)) {
            return $this->constant($class->constants[$name], $name, $at);
        }
        $row = $this->rowsOf((string) ($class->props[$name] ?? $class->computed[$name] ?? ''), $at);
        if ($row !== null) {
            return $this->rowsRef(isset($class->props[$name]) ? 'property' : 'method', $name, $scope->owner, $row, $scope, $at);
        }
        if (isset($class->props[$name])) {
            $type = $this->valueType($class->props[$name], $at, "property \$$name");
            $slint = $scope->model === null ? "root.rk-$name" : 'root.' . $scope->model->bind('property', $name, $type, $scope->owner)->slintName();
            return new Ref($type, $slint, $type === 'list' ? 'string' : null, origin: $scope->model === null ? null : ['property', $name, $scope->owner]);
        }
        if (isset($class->computed[$name]) && in_array($class->computed[$name], array_keys(self::TYPES), true)) {
            if ($scope->model === null) {
                throw TemplateException::at($at, "'$name' is a method; child components can only show their properties (computed values in children aren't supported yet)");
            }
            $type = $class->computed[$name];
            return new Ref($type, 'root.' . $scope->model->bind('method', $name, $type, $scope->owner)->slintName(), $type === 'list' ? 'string' : null, origin: ['method', $name, $scope->owner]);
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

    /** {context.x} in a window's template: method x() of the screen, declared by the window's context() interface. */
    private function context(string $member, Scope $scope, Element $at): Ref
    {
        $info = $this->contextMember($member, $scope, $at);
        $interface = $scope->class->context;
        if ($info['required'] > 0) {
            throw TemplateException::at($at, "'$member()' takes arguments; templates can only show methods without required parameters");
        }
        $type = $info['return'];
        if (!isset(self::TYPES[$type])) {
            throw TemplateException::at($at, $type === 'mixed'
                ? "'$member()' has no return type in $interface; give it one (string, int, float, bool or array) to show it in a template"
                : "'$member()' returns $type; templates can show string, int, float, bool or array (a list of strings)");
        }
        return new Ref($type, 'root.' . $scope->model->bind('method', $member, $type, 'context')->slintName(), $type === 'list' ? 'string' : null, origin: ['method', $member, 'context']);
    }

    /** @return array{return: string, params: list<string>, required: int} */
    private function contextMember(string $member, Scope $scope, Element $at): array
    {
        if (!$scope->inWindow() || $scope->model === null) {
            throw TemplateException::at($at, "context.$member only works in a Window's template");
        }
        $window = $scope->class;
        if ($window->context === '') {
            throw TemplateException::at($at, "{$window->short} has no context(); return the interface its screens implement from context() to use context.$member");
        }
        return $window->contextMembers[$member]
            ?? throw TemplateException::at($at, "'$member' is not declared by {$window->context}");
    }

    private function constant(mixed $value, string $name, Element $at): Ref
    {
        if (is_string($value)) {
            return new Ref('string', TextAttribute::slintString($value));
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
                return new Ref('list', '[' . implode(', ', array_map(fn($v) => TextAttribute::slintString((string) $v), $value)) . ']', 'string');
            }
            $rows = [];
            foreach ($value as $row) {
                if (!is_array($row) || count(array_filter($row, 'is_scalar')) !== count($row)) {
                    throw TemplateException::at($at, "constant $name: only lists of values or lists of lists of values are supported");
                }
                $rows[] = '[' . implode(', ', array_map(fn($v) => TextAttribute::slintString((string) $v), $row)) . ']';
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
