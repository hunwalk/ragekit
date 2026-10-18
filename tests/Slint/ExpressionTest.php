<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Expression;

final class ExpressionTest extends TestCase
{
    public function testSplitsTextAndRefs(): void
    {
        self::assertSame([['text', 'Count: '], ['ref', 'count'], ['text', '!']], Expression::parts('Count: {count}!'));
        self::assertSame([['ref', 'key']], Expression::parts('{key}'));
        self::assertSame([['text', 'a { b }']], Expression::parts('a { b }'));
        self::assertSame([], Expression::parts(''));
        self::assertTrue(Expression::hasRefs('x{y}'));
        self::assertFalse(Expression::hasRefs('x{ y}'));
    }

    public function testEscapesSlintStrings(): void
    {
        self::assertSame('"say \"hi\" \\\\ {x\n"', Expression::slintString("say \"hi\" \\ {x\n"));
    }

    public function testIndent(): void
    {
        self::assertSame("    a\n\n        b\n", Code::indent("a\n\n    b\n", 1));
        self::assertSame('', Code::indent('', 2));
    }
}
