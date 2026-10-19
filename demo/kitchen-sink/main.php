<?php

use Demo\KitchenSinkScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new KitchenSinkScreen(), title: 'RageKit · kitchen sink', width: 680, height: 600);
    $app->run();
}
