<?php

namespace RageKit;

/**
 * The system clipboard's text:
 *
 *     Clipboard::copy($message->text);   // a "Copy" menu item
 *     $this->draft .= Clipboard::paste();
 *
 * It goes through an open window, so both do nothing before the first window opens. On Wayland a
 * desktop may refuse to let a window in the background set the clipboard.
 */
final class Clipboard
{
    private static ?Backend $backend = null;

    /** @internal App's constructor hands over its backend. */
    public static function attach(?Backend $backend): void
    {
        self::$backend = $backend;
    }

    public static function copy(string $text): void
    {
        self::$backend?->copyText($text);
    }

    /** The clipboard's text ('' when it's empty or holds something else, like an image). */
    public static function paste(): string
    {
        return self::$backend?->pasteText() ?? '';
    }
}
