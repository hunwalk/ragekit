<?php

use Demo\PlayerScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new PlayerScreen(), title: 'RageKit · player', width: 420, height: 340);
    $app->run();
}
