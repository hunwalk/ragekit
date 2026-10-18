<?php

use Calculator\CalculatorScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new CalculatorScreen(), title: 'Calculator', width: 320, height: 420);
    $app->run();
}
