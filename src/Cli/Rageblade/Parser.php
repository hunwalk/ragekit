<?php

declare(strict_types=1);

namespace RageKit\Cli\Rageblade;

use RageKit\Cli\Templates\TemplateException;

/**
 * Parses a Rageblade expression into Nodes. PHP's own tokenizer splits it; precedence follows
 * PHP 8 (arithmetic binds tighter than ".", which binds tighter than comparisons).
 */
final class Parser
{
    /** Binary operators by binding power (higher binds tighter). */
    private const BINARY = [
        '||' => 10, '&&' => 20,
        '===' => 30, '!==' => 30, '==' => 30, '!=' => 30,
        '<' => 40, '<=' => 40, '>' => 40, '>=' => 40,
        '.' => 50, '+' => 60, '-' => 60, '*' => 70, '/' => 70, '%' => 70,
    ];

    /** @var list<array{0: string, 1: string}> [kind, text]: var, ident, int, float, string, op */
    private array $tokens = [];
    private int $at = 0;

    private function __construct(private readonly string $source, private readonly string $file, private readonly int $line)
    {
    }

    public static function parse(string $source, string $file, int $line): Node
    {
        $parser = new self($source, $file, $line);
        if (trim($source) === '') {
            throw $parser->error('empty expression');
        }
        $parser->tokenize();
        $node = $parser->expression(0);
        if ($parser->peek() !== null) {
            throw $parser->error('unexpected "' . $parser->peek()[1] . '" in ' . trim($source));
        }
        return $node;
    }

    private function tokenize(): void
    {
        foreach (token_get_all('<?php ' . $this->source . ';') as $token) {
            if (is_string($token)) {
                $this->tokens[] = ['op', $token];
                continue;
            }
            [$id, $text] = $token;
            $kind = match ($id) {
                T_OPEN_TAG, T_WHITESPACE => null,
                T_VARIABLE => 'var',
                T_STRING => 'ident',
                T_LNUMBER => 'int',
                T_DNUMBER => 'float',
                T_CONSTANT_ENCAPSED_STRING => 'string',
                T_ENCAPSED_AND_WHITESPACE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES
                    => throw $this->error('variables inside "..." aren\'t supported; join with . instead'),
                T_STATIC => 'ident',
                default => 'op',
            };
            if ($kind !== null) {
                $this->tokens[] = [$kind, $text];
            }
        }
        array_pop($this->tokens); // the ";" added above
        foreach ($this->tokens as [$kind, $text]) {
            if ($kind === 'op' && $text === '"') {
                throw $this->error('variables inside "..." aren\'t supported; join with . instead');
            }
        }
    }

    /** @return array{0: string, 1: string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->at] ?? null;
    }

    private function take(): array
    {
        $token = $this->peek() ?? throw $this->error('unexpected end of ' . trim($this->source));
        $this->at++;
        return $token;
    }

    private function expect(string $text): void
    {
        $token = $this->take();
        if ($token[1] !== $text) {
            throw $this->error("expected \"$text\", found \"{$token[1]}\" in " . trim($this->source));
        }
    }

    private function isOp(string $text): bool
    {
        $token = $this->peek();
        return $token !== null && $token[0] === 'op' && $token[1] === $text;
    }

    private function expression(int $minPower): Node
    {
        $left = $this->unary();
        while (true) {
            $token = $this->peek();
            if ($token === null || $token[0] !== 'op') {
                return $left;
            }
            $op = $token[1];
            if ($op === '?' && $minPower === 0) {
                $this->at++;
                $then = $this->expression(0);
                $this->expect(':');
                $else = $this->expression(0);
                return new Node('ternary', null, [$left, $then, $else]);
            }
            if ($op === '[') {
                throw $this->error('array access ($x[...]) isn\'t supported: lists hold text');
            }
            $power = self::BINARY[$op] ?? null;
            if ($power === null) {
                if (in_array($op, [')', ']', ',', ':', '=>', '?'], true)) {
                    return $left;
                }
                throw $this->error("unexpected \"$op\" in " . trim($this->source));
            }
            if ($power <= $minPower) {
                return $left;
            }
            $this->at++;
            $left = new Node('binary', $op, [$left, $this->expression($power)]);
        }
    }

    private function unary(): Node
    {
        if ($this->isOp('!')) {
            $this->at++;
            return new Node('not', null, [$this->unary()]);
        }
        if ($this->isOp('-')) {
            $this->at++;
            return new Node('neg', null, [$this->unary()]);
        }
        return $this->primary();
    }

    private function primary(): Node
    {
        [$kind, $text] = $this->take();
        switch ($kind) {
            case 'int':
                return new Node('int', (int) $text);
            case 'float':
                return new Node('float', (float) $text);
            case 'string':
                return new Node('string', self::unquote($text));
            case 'var':
                return $this->variable(substr($text, 1));
            case 'ident':
                return $this->identifier($text);
            default:
                if ($text === '(') {
                    $inner = $this->expression(0);
                    $this->expect(')');
                    return $inner;
                }
                if ($text === '[') {
                    return $this->arrayLiteral();
                }
                throw $this->error("unexpected \"$text\" in " . trim($this->source));
        }
    }

    private function variable(string $name): Node
    {
        if ($name === 'store') {
            return $this->store();
        }
        if ($name === 'assets') {
            return $this->assets();
        }
        if (($name === 'this' || $name === 'context') && $this->isOp('->')) {
            $this->at++;
            [$kind, $method] = $this->take();
            if ($kind !== 'ident' || !$this->isOp('(')) {
                throw $this->error("\$$name->$method: only method calls are supported, e.g. \$$name->title()");
            }
            return new Node($name === 'this' ? 'call' : 'context', $method, $this->arguments());
        }
        if ($name === 'this') {
            throw $this->error('$this on its own isn\'t supported; call a method: $this->name()');
        }
        return new Node('var', $name);
    }

    /** $store->session->userName, $store->session->signedIn(), $store->session->signIn($name) */
    private function store(): Node
    {
        if (!$this->isOp('->')) {
            throw $this->error('$store on its own isn\'t supported; read a store\'s member: $store->session->userName');
        }
        $this->at++;
        [$kind, $store] = $this->take();
        if ($kind !== 'ident') {
            throw $this->error("unexpected \"$store\" after \$store->");
        }
        if (!$this->isOp('->')) {
            throw $this->error('$store->' . $store . ': read one of its members, e.g. $store->' . $store . '->userName');
        }
        $this->at++;
        [$kind, $member] = $this->take();
        if ($kind !== 'ident') {
            throw $this->error('unexpected "' . $member . '" after $store->' . $store . '->');
        }
        $call = $this->isOp('(');
        return new Node('store', [$store, $member, $call], $call ? $this->arguments() : []);
    }

    /** $assets->images->logo: an image the App registered. */
    private function assets(): Node
    {
        $parts = [];
        foreach (['group', 'image'] as $what) {
            if (!$this->isOp('->')) {
                throw $this->error('$assets' . implode('', array_map(fn($p) => "->$p", $parts)) . ": name the $what, e.g. \$assets->images->logo");
            }
            $this->at++;
            [$kind, $part] = $this->take();
            if ($kind !== 'ident') {
                throw $this->error("unexpected \"$part\" after \$assets->" . implode('->', $parts));
            }
            $parts[] = $part;
        }
        return new Node('assets', $parts);
    }

    private function identifier(string $name): Node
    {
        $lower = strtolower($name);
        if ($lower === 'true' || $lower === 'false') {
            return new Node('bool', $lower === 'true');
        }
        if ($lower === 'null') {
            throw $this->error('null isn\'t supported in templates');
        }
        if (($lower === 'self' || $lower === 'static') && $this->isOp('::')) {
            $this->at++;
            [, $constant] = $this->take();
            return new Node('const', $constant);
        }
        if ($this->isOp('(')) {
            return new Node('call', $name, $this->arguments());
        }
        throw $this->error("$name: write constants as self::$name and methods with ()");
    }

    /** @return list<Node> */
    private function arguments(): array
    {
        $this->expect('(');
        $args = [];
        while (!$this->isOp(')')) {
            $args[] = $this->expression(0);
            if (!$this->isOp(')')) {
                $this->expect(',');
            }
        }
        $this->expect(')');
        return $args;
    }

    private function arrayLiteral(): Node
    {
        $items = [];
        while (!$this->isOp(']')) {
            $first = $this->expression(0);
            if ($this->isOp('=>')) {
                $this->at++;
                $items[] = new Node('item', null, [$first, $this->expression(0)]);
            } else {
                $items[] = new Node('item', null, [$first, null]);
            }
            if (!$this->isOp(']')) {
                $this->expect(',');
            }
        }
        $this->expect(']');
        return new Node('array', null, $items);
    }

    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);
        return $literal[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner) : stripcslashes($inner);
    }

    private function error(string $message): TemplateException
    {
        return new TemplateException($this->file, $this->line, $message);
    }
}
