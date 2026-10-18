<?php

use Demo\KitchenSinkScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new KitchenSinkScreen(), title: 'RageKit · kitchen sink', width: 640, height: 560);
    $app->run();
}
