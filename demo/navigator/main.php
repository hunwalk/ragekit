<?php

use Demo\AlbumsScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new AlbumsScreen(), title: 'RageKit · navigator', width: 520, height: 420);
    $app->run();
}
