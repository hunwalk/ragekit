<?php

namespace Fix;

interface TitleBarContext
{
    public function title(): string;

    public function refresh(string $why = ''): void;
}
