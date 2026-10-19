<?php

use Demo\FetchScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new FetchScreen(), title: 'RageKit · fetch', width: 460, height: 380);
    $app->run();
}
