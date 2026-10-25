<?php

namespace RageKit;

/**
 * What a UI backend (Slint, …) provides to App. Exactly one backend is compiled into an
 * app: its sources come from src/<Name>/backend.yml; its backend.php defines ragekit_backend().
 */
interface Backend
{
    /** Opens a window showing $screen: an instance of the screen's window() class. */
    public function open(App $app, Screen $screen, string $title, int $width, int $height): Window;

    /** Closes one window. */
    public function close(Window $window): void;

    public function minimize(int $window): void;

    public function setMaximized(int $window, bool $maximized): void;

    /** Closes every window and ends run(). */
    public function quit(): void;

    /** Runs the event loop until quit() (or the last window closed). */
    public function run(): void;

    /** The app's folder: where the executable and its assets are (in dev mode: the project). */
    public function appDir(): string;

    /** Writes one line to the app's error output (stderr). */
    public function printError(string $line): void;

    /**
     * Starts native timer $id (see Timer): it fires every $milliseconds, or once, as the event
     * Events::deliver('@timer', $id, 'tick', '') on the UI thread.
     */
    public function startTimer(int $id, int $milliseconds, bool $repeat): void;

    public function stopTimer(int $id): void;
}
