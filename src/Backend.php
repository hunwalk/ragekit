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

    /** A folder for the app's own cache files (created): ~/.cache/<app> on Linux, %LOCALAPPDATA%\<app> on Windows. */
    public function cacheDir(): string;

    /** Writes one line to the app's error output (stderr). */
    public function printError(string $line): void;

    /**
     * Starts native timer $id (see Timer): it fires every $milliseconds, or once, as the event
     * Events::deliver('@timer', $id, 'tick', '') on the UI thread.
     */
    public function startTimer(int $id, int $milliseconds, bool $repeat): void;

    public function stopTimer(int $id): void;

    /** Hides a window without closing it, or shows it again. */
    public function setVisible(int $window, bool $visible): void;

    /** Brings a window to the front (as far as the OS's window manager allows). */
    public function raise(int $window): void;

    /** @return list<string> the URL schemes the app declared (project.yml `ragekit: { links: [...] }`) */
    public function links(): array;

    /**
     * For an app that declares links: true when this is its running instance; false when this launch
     * handed its link (or "come to the front") to the running one and should end. Links arrive as
     * Events::deliver('@links', 0, 'open', $url) / ('@links', 0, 'focus', '').
     */
    public function claimInstance(): bool;

    /** Makes the app the OS's handler for $scheme: links; '' or why it couldn't. */
    public function registerLink(string $scheme): string;

    /** Seconds east of UTC of the computer's local time at unix time $at. */
    public function utcOffset(int $at): int;

    /** Opens $url in the user's browser; '' or why it couldn't. */
    public function openUrl(string $url): string;
}
