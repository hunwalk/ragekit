<?php

namespace Calculator;

use RageKit\Screen;

/** The window: a display and a 4×5 keypad, all driving one Engine. */
final class CalculatorScreen extends Screen
{
    public const KEYPAD = [
        ['C', '±', '%', '÷'],
        ['7', '8', '9', '×'],
        ['4', '5', '6', '−'],
        ['1', '2', '3', '+'],
        ['0', '.', '='],
    ];

    private Engine $engine;

    public static function template(): string
    {
        return 'templates/CalculatorScreen.rk';
    }

    public function __construct()
    {
        $this->engine = new Engine();
    }

    public function display(): string
    {
        return $this->engine->display();
    }

    public function pending(): string
    {
        return $this->engine->pending();
    }

    public function press(string $key): void
    {
        $this->engine->press($key);
    }
}
