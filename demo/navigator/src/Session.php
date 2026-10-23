<?php

namespace Demo;

use RageKit\Store;

/** Who is signed in. It says what happened (signedIn, signedOut); GalleryApp decides where that leads. */
final class Session extends Store
{
    /** '' = nobody. */
    public string $userName = '';

    public function signedIn(): bool
    {
        return $this->userName !== '';
    }

    public function signIn(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $this->userName = $name;
        $this->emit('signedIn');
    }

    public function signOut(): void
    {
        $this->userName = '';
        $this->emit('signedOut');
    }
}
