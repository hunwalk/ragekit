<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Rageblade\Node;
use RageKit\Cli\Rageblade\Parser;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * Rageblade expressions → typed Slint expressions. A strict PHP subset: conditions must be bools,
 * == means === between values of one type (int and float may mix), / gives a float, % takes whole
 * numbers, and . joins text and numbers. Values come from the component like {name} always did
 * (bound properties and computed methods), so they update when the state changes.
 */
final class ExpressionCompiler
{
    public function __construct(private readonly ValueCompiler $values)
    {
    }

    public function compile(Node $node, Scope $scope, Element $at): Ref
    {
        switch ($node->kind) {
            case 'int':
                return new Ref('int', (string) $node->value);
            case 'float':
                $text = var_export((float) $node->value, true);
                return new Ref('float', str_contains($text, '.') || str_contains($text, 'E') ? $text : "$text.0");
            case 'string':
                return new Ref('string', Expression::slintString((string) $node->value));
            case 'bool':
                return new Ref('bool', $node->value ? 'true' : 'false');
            case 'var':
                return $this->variable((string) $node->value, $scope, $at);
            case 'const':
                return $this->constant((string) $node->value, $scope, $at);
            case 'call':
                return $this->call($node, $scope, $at);
            case 'context':
                if ($node->children !== []) {
                    throw TemplateException::at($at, "\$context->{$node->value}(): methods shown in templates can't take arguments");
                }
                return $this->values->resolve('context.' . $node->value, $scope, $at);
            case 'not':
                return new Ref('bool', '!(' . $this->condition($node->children[0], $scope, $at)->slint . ')');
            case 'neg':
                $value = $this->numeric($node->children[0], $scope, $at, '-');
                return new Ref($value->type, '-(' . $value->slint . ')');
            case 'binary':
                return $this->binary($node, $scope, $at);
            case 'ternary':
                $condition = $this->condition($node->children[0], $scope, $at);
                $then = $this->compile($node->children[1], $scope, $at);
                $else = $this->compile($node->children[2], $scope, $at);
                return new Ref($this->common($then, $else, '?:', $at), "({$condition->slint} ? {$then->slint} : {$else->slint})");
            default:
                throw TemplateException::at($at, 'arrays are only allowed in @style([...])');
        }
    }

    /** An attribute's text with {{ expressions }} and {name} references, as a Slint string. */
    public function compileText(string $raw, Scope $scope, Element $at): string
    {
        $out = '';
        $offset = 0;
        preg_match_all('/\{\{(.*?)\}\}|\{((?:context\.)?[A-Za-z_][A-Za-z0-9_]*)\}/s', $raw, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $out .= Expression::escape(substr($raw, $offset, $match[0][1] - $offset));
            $offset = $match[0][1] + strlen($match[0][0]);
            if (isset($match[2]) && $match[2][1] >= 0) {
                $ref = $this->values->resolve($match[2][0], $scope, $at);
                if ($ref->type === 'list') {
                    throw TemplateException::at($at, "'{$match[2][0]}' is a list; show its items with @foreach");
                }
                $out .= $ref->type === 'bool' ? '\{(' . $ref->slint . ' ? "true" : "false")}' : '\{' . $ref->slint . '}';
                continue;
            }
            $node = Parser::parse($match[1][0], $at->file, $at->line);
            $out .= $this->textPart($node, $scope, $at);
        }
        return '"' . $out . Expression::escape(substr($raw, $offset)) . '"';
    }

    /** A value that must be a bool (conditions of @if, ?:, !, &&, ||, @style). */
    public function condition(Node $node, Scope $scope, Element $at): Ref
    {
        $ref = $this->compile($node, $scope, $at);
        if ($ref->type !== 'bool') {
            $what = self::describe($node);
            throw TemplateException::at($at, "the condition $what is " . self::article($ref->type) . "; conditions need a bool (e.g. $what > 0)");
        }
        return $ref;
    }

    private function variable(string $name, Scope $scope, Element $at): Ref
    {
        $item = $scope->item($name);
        if ($item !== null) {
            return $item;
        }
        $class = $scope->class;
        if (isset($class->computed[$name]) || isset($class->handlers[$name])) {
            throw TemplateException::at($at, "$name is a method: write $name()");
        }
        if (array_key_exists($name, $class->constants)) {
            throw TemplateException::at($at, "$name is a constant: write self::$name");
        }
        if (!isset($class->props[$name])) {
            throw TemplateException::at($at, "'$name' is not a property, method or constant of {$class->short}");
        }
        return $this->values->resolve($name, $scope, $at);
    }

    private function constant(string $name, Scope $scope, Element $at): Ref
    {
        if (!array_key_exists($name, $scope->class->constants)) {
            throw TemplateException::at($at, "self::$name is not a constant of {$scope->class->short}");
        }
        return $this->values->resolve($name, $scope, $at);
    }

    private function call(Node $node, Scope $scope, Element $at): Ref
    {
        $name = (string) $node->value;
        $class = $scope->class;
        if ($name === 'count' && !isset($class->handlers['count'])) {
            if (count($node->children) !== 1) {
                throw TemplateException::at($at, 'count() takes one list');
            }
            $list = $this->compile($node->children[0], $scope, $at);
            if ($list->type !== 'list') {
                throw TemplateException::at($at, 'count() takes a list, not ' . self::article($list->type));
            }
            return new Ref('int', $list->slint . '.length');
        }
        if ($node->children !== []) {
            throw TemplateException::at($at, "$name(): methods shown in templates can't take arguments");
        }
        if (isset($class->props[$name]) && !isset($class->handlers[$name])) {
            throw TemplateException::at($at, "$name is a property: write \$$name");
        }
        return $this->values->resolve($name, $scope, $at);
    }

    private function binary(Node $node, Scope $scope, Element $at): Ref
    {
        $op = (string) $node->value;
        if ($op === '.') {
            return new Ref('string', '"' . implode('', array_map(fn(Node $part) => $this->textPart($part, $scope, $at), self::joined($node))) . '"');
        }
        if ($op === '&&' || $op === '||') {
            $left = $this->condition($node->children[0], $scope, $at);
            $right = $this->condition($node->children[1], $scope, $at);
            return new Ref('bool', "({$left->slint} $op {$right->slint})");
        }
        $left = $this->compile($node->children[0], $scope, $at);
        $right = $this->compile($node->children[1], $scope, $at);
        if (in_array($op, ['===', '==', '!==', '!='], true)) {
            $numbers = in_array($left->type, ['int', 'float'], true) && in_array($right->type, ['int', 'float'], true);
            if (($left->type !== $right->type && !$numbers) || $left->type === 'list') {
                throw TemplateException::at($at, "$op compares " . self::article($left->type) . ' with ' . self::article($right->type));
            }
            $slintOp = $op[0] === '!' ? '!=' : '==';
            return new Ref('bool', "({$left->slint} $slintOp {$right->slint})");
        }
        if (in_array($op, ['<', '<=', '>', '>='], true)) {
            $this->requireNumbers($left, $right, "$op compares numbers only", $at);
            return new Ref('bool', "({$left->slint} $op {$right->slint})");
        }
        $this->requireNumbers($left, $right, $op === '+' ? '+ needs numbers; join text with .' : "$op needs numbers", $at);
        if ($op === '%') {
            if ($left->type !== 'int' || $right->type !== 'int') {
                throw TemplateException::at($at, '% needs whole numbers');
            }
            return new Ref('int', "Math.mod({$left->slint}, {$right->slint})");
        }
        if ($op === '/') {
            return new Ref('float', "({$left->slint} * 1.0 / {$right->slint})");
        }
        $type = $left->type === 'int' && $right->type === 'int' ? 'int' : 'float';
        return new Ref($type, "({$left->slint} $op {$right->slint})");
    }

    private function requireNumbers(Ref $left, Ref $right, string $message, Element $at): void
    {
        foreach ([$left, $right] as $ref) {
            if (!in_array($ref->type, ['int', 'float'], true)) {
                throw TemplateException::at($at, $message);
            }
        }
    }

    private function numeric(Node $node, Scope $scope, Element $at, string $op): Ref
    {
        $ref = $this->compile($node, $scope, $at);
        if (!in_array($ref->type, ['int', 'float'], true)) {
            throw TemplateException::at($at, "$op needs a number");
        }
        return $ref;
    }

    /** One piece of joined text: literal text inline, anything else as \{…} (bools and lists refused). */
    private function textPart(Node $node, Scope $scope, Element $at): string
    {
        if ($node->kind === 'string') {
            return Expression::escape((string) $node->value);
        }
        if ($node->kind === 'binary' && $node->value === '.') {
            return implode('', array_map(fn(Node $part) => $this->textPart($part, $scope, $at), self::joined($node)));
        }
        $ref = $this->compile($node, $scope, $at);
        if ($ref->type === 'bool') {
            $what = self::describe($node);
            throw TemplateException::at($at, "can't join a bool to text; use a ternary, e.g. ($what ? 'yes' : 'no')");
        }
        if ($ref->type === 'list') {
            throw TemplateException::at($at, self::describe($node) . ' is a list; show its items with @foreach');
        }
        return '\{' . $ref->slint . '}';
    }

    /** @return list<Node> the operands of a chain of . */
    private static function joined(Node $node): array
    {
        if ($node->kind === 'binary' && $node->value === '.') {
            return [...self::joined($node->children[0]), ...self::joined($node->children[1])];
        }
        return [$node];
    }

    private function common(Ref $a, Ref $b, string $op, Element $at): string
    {
        if ($a->type === $b->type) {
            return $a->type;
        }
        if (in_array($a->type, ['int', 'float'], true) && in_array($b->type, ['int', 'float'], true)) {
            return 'float';
        }
        throw TemplateException::at($at, "the two results of $op have different types (" . $a->type . ' and ' . $b->type . ')');
    }

    private static function describe(Node $node): string
    {
        return match ($node->kind) {
            'var' => '$' . $node->value,
            'call' => $node->value . '()',
            'context' => '$context->' . $node->value . '()',
            'const' => 'self::' . $node->value,
            default => 'this value',
        };
    }

    private static function article(string $type): string
    {
        return ($type === 'int' ? 'an ' : 'a ') . $type;
    }
}
