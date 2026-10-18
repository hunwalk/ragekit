<?php

use KitDemo\PlayerScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new PlayerScreen(), title: 'Kit demo', width: 380, height: 220);
    $app->run();
}
