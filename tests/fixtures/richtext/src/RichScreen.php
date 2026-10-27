<?php

namespace Fix;

use RageKit\Screen;

/** Formatted messages in bubbles, a link that prints its URL, and colour emoji. */
final class RichScreen extends Screen
{
    public const STYLES = [
        'bubble' => ['backgroundColor' => '#27272a', 'borderRadius' => 12, 'paddingHorizontal' => 10, 'paddingVertical' => 6, 'maxWidth' => 360],
    ];

    /** @var list<string> */
    public array $messages = [
        'Hello **bold** and _italic_ with `code` :tada:',
        "Open [the docs](https://example.com/docs) or https://example.org/x.\nSecond line",
        "```\nfn main() {\n    hi();\n}\n```",
    ];

    public string $reaction = '👍❤️😂🇭🇺';

    public static function template(): string
    {
        return 'templates/RichScreen.rk';
    }

    public function open(string $url): void
    {
        echo 'link ' . $url . "\n";
    }

    public function reply(string $message): void
    {
        echo 'reply ' . strtok($message, "\n") . "\n";
    }
}
