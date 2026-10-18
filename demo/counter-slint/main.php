<?php

use Demo\CounterScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new CounterScreen(), title: 'RageKit · counter', width: 360, height: 420);
    $app->run();
}
