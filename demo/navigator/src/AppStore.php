<?php

namespace Demo;

use RageKit\RootStore;

/** The app's state: templates read it as $store->session->…, PHP as AppStore::get()->session. */
final class AppStore extends RootStore
{
    public Session $session;

    public function __construct()
    {
        parent::__construct();
        $this->session = new Session();
    }
}
