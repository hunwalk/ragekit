<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\TextAttribute;

final class TextAttributeTest extends TestCase
{
    public function testSplitsTextAndRefs(): void
    {
        self::assertSame([['text', 'Count: '], ['ref', 'count'], ['text', '!']], TextAttribute::parts('Count: {count}!'));
        self::assertSame([['ref', 'key']], TextAttribute::parts('{key}'));
        self::assertSame([['text', 'a { b }']], TextAttribute::parts('a { b }'));
        self::assertSame([], TextAttribute::parts(''));
        self::assertTrue(TextAttribute::hasRefs('x{y}'));
        self::assertFalse(TextAttribute::hasRefs('x{ y}'));
    }

    public function testEscapesSlintStrings(): void
    {
        self::assertSame('"say \"hi\" \\\\ {x\n"', TextAttribute::slintString("say \"hi\" \\ {x\n"));
    }

    public function testIndent(): void
    {
        self::assertSame("    a\n\n        b\n", Code::indent("a\n\n    b\n", 1));
        self::assertSame('', Code::indent('', 2));
    }
}
