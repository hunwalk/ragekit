<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Emoji;

final class EmojiTest extends TestCase
{
    public function testSequencesCountAsOne(): void
    {
        self::assertSame(1, Emoji::count('👍'));
        self::assertSame(1, Emoji::count('👍🏽'));
        self::assertSame(1, Emoji::count('👩‍💻'));
        self::assertSame(1, Emoji::count('🇭🇺'));
        self::assertSame(1, Emoji::count('❤️'));
        self::assertSame(1, Emoji::count('1️⃣'));
        self::assertSame(3, Emoji::count('😂 😂😂'));
        self::assertSame(2, Emoji::count('🇭🇺🇩🇪'));
    }

    public function testAnythingElseMeansNotOnlyEmoji(): void
    {
        self::assertSame(-1, Emoji::count('hi 👍'));
        self::assertSame(-1, Emoji::count('1'));
        self::assertSame(-1, Emoji::count('👍!'));
        self::assertSame(0, Emoji::count(''));
        self::assertTrue(Emoji::only('🎉🔥'));
        self::assertFalse(Emoji::only('🎉🔥😀🙂'));
        self::assertTrue(Emoji::only('🎉🔥😀🙂', 4));
        self::assertFalse(Emoji::only(''));
        self::assertFalse(Emoji::only('ok'));
    }

    public function testSuggestShortcodes(): void
    {
        self::assertSame(['thumbsdown' => '👎', 'thumbsup' => '👍'], Emoji::suggest('thu'));
        self::assertSame('tada', array_key_first(Emoji::suggest('tada')));
        self::assertSame(['100' => '💯'], Emoji::suggest('10'));
        self::assertCount(2, Emoji::suggest('s', 2));
        self::assertSame([], Emoji::suggest('nothinglikethis'));
    }
}
