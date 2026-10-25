<?php

namespace E2e;

final class Device
{
    public function __construct(public string $name = '', public string $image = '')
    {
    }
}
