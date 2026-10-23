<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** What a window's action(id, arg) callback does in PHP: call a handler or set a two-way property, on a page's screen, the window or the screen on top ($owner: screen, window, context). */
final class Action
{
    /**
     * @param list<array{0: 'literal'|'arg', 1: string}> $args
     * @param list<string>|null $params the handler's parameter types (null: look them up on the screen class)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $target,
        public readonly array $args = [],
        public readonly ?Binding $binding = null,
        public readonly string $owner = 'screen',
        public readonly ?array $params = null,
    ) {
    }
}
