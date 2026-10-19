<?php

use Demo\HomeScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new HomeScreen(), title: 'RageKit · title bar', width: 640, height: 400);
    $app->run();
}
