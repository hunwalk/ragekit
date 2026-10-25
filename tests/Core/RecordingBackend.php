<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use RageKit\App;
use RageKit\Backend;
use RageKit\Navigator;
use RageKit\Screen;
use RageKit\Window;

/** A backend that opens plain Windows (no UI) and writes what it was asked to do to $calls. */
final class RecordingBackend implements Backend
{
    /** @var list<string> */
    public array $calls = [];

    private int $next = 0;

    public function open(App $app, Screen $screen, string $title, int $width, int $height): Window
    {
        $window = new Window();
        $window->attach($app, ++$this->next);
        new Navigator($window, $screen, fn(Screen $s): string => 'rk-screens');
        $class = \get_class($screen);
        $this->calls[] = 'open ' . substr($class, strrpos($class, '\\') + 1) . " $title {$width}x{$height}";
        return $window;
    }

    public function close(Window $window): void
    {
        $this->calls[] = "close $window->id";
    }

    public function minimize(int $window): void
    {
        $this->calls[] = "minimize $window";
    }

    public function setMaximized(int $window, bool $maximized): void
    {
        $this->calls[] = "maximize $window " . ($maximized ? '1' : '0');
    }

    public function quit(): void
    {
        $this->calls[] = 'quit';
    }

    public function run(): void
    {
        $this->calls[] = 'run';
    }

    public function appDir(): string
    {
        return '/app';
    }

    public function printError(string $line): void
    {
        $this->calls[] = "error $line";
    }

    public function startTimer(int $id, int $milliseconds, bool $repeat): void
    {
        $this->calls[] = "timer-start $id $milliseconds " . ($repeat ? '1' : '0');
    }

    public function stopTimer(int $id): void
    {
        $this->calls[] = "timer-stop $id";
    }
}
