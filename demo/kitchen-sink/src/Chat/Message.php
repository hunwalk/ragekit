<?php

namespace Demo\Chat;

/** A chat bubble. */
final class Message
{
    public function __construct(public int $id = 0, public string $text = '', public bool $mine = false, public string $time = '')
    {
    }

    /** One to three emoji and nothing else: shown big, in colour. */
    public function bigEmoji(): bool
    {
        return \RageKit\Emoji::only($this->text);
    }
}
