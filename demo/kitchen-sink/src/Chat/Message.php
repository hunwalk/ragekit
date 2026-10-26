<?php

namespace Demo\Chat;

/** A chat bubble. */
final class Message
{
    public function __construct(public int $id = 0, public string $text = '', public bool $mine = false, public string $time = '')
    {
    }
}
