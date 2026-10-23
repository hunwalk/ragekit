<?php

namespace Fix;

use RageKit\Store;

final class Session extends Store
{
    public string $userName = '';

    public function signedIn(): bool
    {
        return $this->userName !== '';
    }

    public function signIn(string $name): void
    {
        $this->userName = $name;
    }

    public function signOut(): void
    {
        $this->userName = '';
        $this->emit('signedOut');
    }
}
