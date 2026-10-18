<?php

namespace RageKit;

/** Native modules (modules/<name>) are loaded when first used; an app runs without them. */
final class Modules
{
    public static function available(string $name): bool
    {
        return \ragekit_module_available($name);
    }

    /** @throws ModuleUnavailable when the module's library can't be loaded */
    public static function require(string $name): void
    {
        if (!\ragekit_module_available($name)) {
            throw new ModuleUnavailable($name . ' module not available: ' . \ragekit_module_error($name));
        }
    }
}
