<?php

namespace Fix;

final class Message
{
    public function __construct(public int $id = 0, public string $text = '')
    {
    }
}
