<?php

namespace RageKit;

/**
 * What a UI backend (Slint, …) provides to App. Exactly one backend is compiled into an
 * app: its sources come from src/<Name>/backend.yml; its backend.php defines ragekit_backend().
 */
interface Backend
{
    /** Opens a window showing $screen; returns the window id. */
    public function open(App $app, Screen $screen, string $title, int $width, int $height): int;

    /** Closes a window. Closing the last one ends run(). */
    public function close(int $window): void;

    public function quit(): void;

    /** Runs the event loop until the last window closes or quit() is called. */
    public function run(): void;

    /** The app's folder: where the executable and its assets are (in dev mode: the project). */
    public function appDir(): string;
}
