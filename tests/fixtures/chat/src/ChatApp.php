<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

final class ChatApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ChatScreen();
    }

    protected function title(): string
    {
        return 'chat';
    }

    protected function height(): int
    {
        return 400;
    }
}
