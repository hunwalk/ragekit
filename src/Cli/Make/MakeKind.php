<?php

declare(strict_types=1);

namespace RageKit\Cli\Make;

/** What `ragekit make:<kind>` creates, and where (src/<folder>, templates/<folder>). */
enum MakeKind: string
{
    case Screen = 'screen';
    case Window = 'window';
    case Component = 'component';
    case Store = 'store';
    case Service = 'service';
    case Flow = 'flow';
    case Assets = 'assets';

    public function folder(): string
    {
        return match ($this) {
            self::Screen => 'Screens',
            self::Window => 'Windows',
            self::Component => 'Components',
            self::Store => 'Stores',
            self::Service => 'Services',
            self::Flow => 'Flows',
            self::Assets => 'Assets',
        };
    }

    /** Added to the name unless it's already there: Login → LoginScreen. */
    public function suffix(): string
    {
        return match ($this) {
            self::Screen => 'Screen',
            self::Window => 'Window',
            default => '',
        };
    }

    /** Whether it also gets a .rk template. */
    public function hasTemplate(): bool
    {
        return in_array($this, [self::Screen, self::Window, self::Component], true);
    }
}
