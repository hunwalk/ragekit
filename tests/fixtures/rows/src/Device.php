<?php

namespace Fix;

final class Device
{
    public function __construct(public string $name = '', public string $image = '', public float $level = 0.0, public int $count = 0, public bool $on = false)
    {
    }

    public function label(): string
    {
        return $this->name . ' (' . $this->count . ')';
    }
}
