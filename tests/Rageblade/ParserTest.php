<?php

declare(strict_types=1);

namespace RageKit\Tests\Rageblade;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Rageblade\Node;
use RageKit\Cli\Rageblade\Parser;
use RageKit\Cli\Templates\TemplateException;

final class ParserTest extends TestCase
{
    /** A compact, readable form of a tree. */
    private static function show(Node $n): string
    {
        return match ($n->kind) {
            'int', 'float' => (string) $n->value,
            'string' => "'" . $n->value . "'",
            'bool' => $n->value ? 'true' : 'false',
            'var' => '$' . $n->value,
            'const' => 'self::' . $n->value,
            'call' => $n->value . '(' . implode(', ', array_map(self::show(...), $n->children)) . ')',
            'context' => '$context->' . $n->value . '(' . implode(', ', array_map(self::show(...), $n->children)) . ')',
            'store' => '$store->' . $n->value[0] . '->' . $n->value[1] . ($n->value[2] ? '(' . implode(', ', array_map(self::show(...), $n->children)) . ')' : ''),
            'not' => '!' . self::show($n->children[0]),
            'neg' => '-' . self::show($n->children[0]),
            'binary' => '(' . self::show($n->children[0]) . ' ' . $n->value . ' ' . self::show($n->children[1]) . ')',
            'ternary' => '(' . self::show($n->children[0]) . ' ? ' . self::show($n->children[1]) . ' : ' . self::show($n->children[2]) . ')',
            'array' => '[' . implode(', ', array_map(fn(Node $i) => ($i->children[1] ?? null) !== null ? self::show($i->children[0]) . ' => ' . self::show($i->children[1]) : self::show($i->children[0]), $n->children)) . ']',
        };
    }

    private static function p(string $src): string
    {
        return self::show(Parser::parse($src, 'T.rk', 3));
    }

    public function testPrecedence(): void
    {
        self::assertSame('(1 + (2 * 3))', self::p('1 + 2 * 3'));
        self::assertSame('((!$a && $b) || $c)', self::p('!$a && $b || $c'));
        self::assertSame("((\$name . (\$n + 1)) . ' x')", self::p("\$name . \$n + 1 . ' x'"));
        self::assertSame('(($a === 1) ? $b : ($c ? 2 : 3))', self::p('$a === 1 ? $b : ($c ? 2 : 3)'));
        self::assertSame('($count > (0 - 1))', self::p('$count > 0 - 1'));
        self::assertSame('-$x', self::p('-$x'));
    }

    public function testConcatIsLeftAssociative(): void
    {
        self::assertSame("((\$a . 'b') . \$c)", self::p("\$a . 'b' . \$c"));
    }

    public function testCallsAndConstants(): void
    {
        self::assertSame('greeting()', self::p('$this->greeting()'));
        self::assertSame('greeting()', self::p('greeting()'));
        self::assertSame('count($items)', self::p('count($items)'));
        self::assertSame("\$context->refresh('bar')", self::p("\$context->refresh('bar')"));
        self::assertSame('self::LIMIT', self::p('self::LIMIT'));
        self::assertSame('self::LIMIT', self::p('static::LIMIT'));
        self::assertSame('true', self::p('true'));
        self::assertSame('1.5', self::p('1.5'));
        self::assertSame("'it's'", self::p("'it\\'s'"));
    }

    public function testStorePaths(): void
    {
        self::assertSame('$store->session->userName', self::p('$store->session->userName'));
        self::assertSame('$store->session->signedIn()', self::p('$store->session->signedIn()'));
        self::assertSame("\$store->session->signIn(\$name, 'x')", self::p("\$store->session->signIn(\$name, 'x')"));
        self::assertSame('(!$store->session->signedIn() && $open)', self::p('!$store->session->signedIn() && $open'));
    }

    public function testArrayLiteral(): void
    {
        self::assertSame("['row', 'selected' => (\$item === \$sel)]", self::p("['row', 'selected' => \$item === \$sel]"));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function errors(): iterable
    {
        yield 'assignment' => ['$a = 1', 'T.rk:3: unexpected "=" in $a = 1'];
        yield 'array access' => ["\$x['k']", "T.rk:3: array access (\$x[...]) isn't supported: lists hold text"];
        yield 'interpolation' => ['"hi $name"', 'T.rk:3: variables inside "..." aren\'t supported; join with . instead'];
        yield 'trailing' => ['$a $b', 'T.rk:3: unexpected "$b" in $a $b'];
        yield 'bare name' => ['LIMIT', 'T.rk:3: LIMIT: write constants as self::LIMIT and methods with ()'];
        yield 'empty' => ['', 'T.rk:3: empty expression'];
        yield 'store alone' => ['$store', 'T.rk:3: $store on its own isn\'t supported; read a store\'s member: $store->session->userName'];
        yield 'store only' => ['$store->session', 'T.rk:3: $store->session: read one of its members, e.g. $store->session->userName'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(string $src, string $message): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($message);
        Parser::parse($src, 'T.rk', 3);
    }
}
