<?php

use Fix\HomeScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new HomeScreen(), title: 'layouts', width: 480, height: 320);
    $app->run();
}
