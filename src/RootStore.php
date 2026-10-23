<?php

namespace RageKit;

/**
 * The app's state: its stores are typed public properties, created in the constructor.
 *
 *     final class AppStore extends RootStore
 *     {
 *         public Session $session;
 *
 *         public function __construct()
 *         {
 *             parent::__construct();
 *             $this->session = new Session();
 *         }
 *     }
 *
 * The App creates it (App::createStore()); code reaches it typed as AppStore::get(). A new root
 * replaces the old one, so a test starts clean with `new AppStore()`.
 */
class RootStore
{
    private static ?RootStore $current = null;

    public function __construct()
    {
        self::$current = $this;
    }

    /** The app's root store, as the class it's called on: AppStore::get()->session. */
    public static function get(): static
    {
        $current = self::$current;
        if ($current instanceof static) {
            return $current;
        }
        $class = static::class;
        $slash = strrpos($class, '\\');
        $short = $slash === false ? $class : substr($class, $slash + 1);
        throw new \LogicException("no $short yet: return new $short() from your App's createStore() (and call parent::__construct() in $short's constructor)");
    }

    /** @internal Makes $root the app's root store (App::run() does, whatever its constructor did). */
    public static function register(RootStore $root): void
    {
        self::$current = $root;
    }

    /** @internal The root store, whatever its class (null before the app made one). */
    public static function current(): ?RootStore
    {
        return self::$current;
    }
}
