<?php

namespace Fix;

use RageKit\RootStore;

final class AppStore extends RootStore
{
    public Session $session;

    public function __construct()
    {
        parent::__construct();
        $this->session = new Session();
    }
}
