<?php

namespace RageKit;

/**
 * The system clipboard's text, and images on it:
 *
 *     Clipboard::copy($message->text);   // a "Copy" menu item
 *     $this->draft .= Clipboard::paste();
 *     $path = Clipboard::image();         // e.g. from <PasteImage onPaste="…" /> in a template
 *
 * Text goes through an open window, so copy() and paste() do nothing before the first window
 * opens. On Wayland a desktop may refuse to let a window in the background set the clipboard.
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

    /**
     * The clipboard's image (a screenshot, an image copied in a browser, or one image file copied in
     * a file manager), saved as a file in the app's cache folder: its path, or '' when there's no
     * image. Named by its content (pasted-<hash>.png/.jpg/…), so pasting the same image twice gives
     * the same file. Windows bitmaps are saved as png.
     */
    public static function image(): string
    {
        $backend = self::$backend;
        if ($backend === null) {
            return '';
        }
        return $backend->pasteImage($backend->cacheDir() . '/pasted');
    }

    /** Whether the clipboard holds an image (see image()). */
    public static function hasImage(): bool
    {
        return self::$backend?->clipboardHasImage() ?? false;
    }
}
