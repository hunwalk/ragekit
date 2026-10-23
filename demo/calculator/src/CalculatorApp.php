<?php

namespace Calculator;

use RageKit\App;
use RageKit\Screen;

/** A four-function calculator in one window. */
final class CalculatorApp extends App
{
    protected function firstScreen(): Screen
    {
        return new CalculatorScreen();
    }

    protected function title(): string
    {
        return 'Calculator';
    }

    protected function width(): int
    {
        return 320;
    }

    protected function height(): int
    {
        return 420;
    }
}
