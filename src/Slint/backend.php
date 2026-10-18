<?php

// Compiled into apps using the Slint backend (see backend.yml); App calls this.
function ragekit_backend(): \RageKit\Backend
{
    return new \RageKit\Slint\SlintBackend();
}
