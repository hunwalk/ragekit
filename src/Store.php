<?php

namespace RageKit;

/**
 * One part of the app's state (the signed-in user, settings, a cart): public properties plus
 * methods that change them. Register it as a typed property of the app's RootStore; templates
 * read it as {{ $store->session->userName }}.
 *
 * A store tells the rest of the app what happened with named events — `$this->emit('signedOut')`
 * — and whoever cares listens: `$store->session->on('signedOut', fn() => …)`. Stores never
 * navigate; the app class decides what an event means for the windows.
 */
abstract class Store
{
    /** @var array<string, list<\Closure>> listeners by event name */
    private array $listeners = [];

    /** Calls $listener whenever this store emits $event (in the order they were added). */
    public function on(string $event, \Closure $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** Tells every listener of $event, right away. */
    protected function emit(string $event): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener();
        }
    }
}
