<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Clipboard;

/** RageKit\Clipboard goes to the backend (in a running app: tests/e2e/clipboard.sh). */
final class ClipboardTest extends TestCase
{
    protected function tearDown(): void
    {
        Clipboard::attach(null);
    }

    public function testCopyAndPasteGoThroughTheBackend(): void
    {
        $backend = new RecordingBackend();
        Clipboard::attach($backend);
        Clipboard::copy("two\nlines ✓");
        self::assertSame(["copy two\nlines ✓"], $backend->calls);
        self::assertSame("two\nlines ✓", Clipboard::paste());
    }

    public function testNothingWithoutAnApp(): void
    {
        Clipboard::copy('x');
        self::assertSame('', Clipboard::paste());
    }
}
