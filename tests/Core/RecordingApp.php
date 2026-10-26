<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use RageKit\App;
use RageKit\RootStore;
use RageKit\Screen;

/** An App on a RecordingBackend (App's constructor would ask the compiled-in backend). */
class RecordingApp extends App
{
    public RecordingBackend $recorder;

    public ?Screen $first = null;

    public ?RootStore $root = null;

    /** Replaces mainWindowClosed() when set (a tray app would hide the window instead). */
    public ?\Closure $onMainClosed = null;

    public static function make(): static
    {
        $app = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $app->recorder = new RecordingBackend();
        (new \ReflectionProperty(App::class, 'backend'))->setValue($app, $app->recorder);
        return $app;
    }

    protected function createStore(): RootStore
    {
        $this->recorder->calls[] = 'createStore';
        return $this->root ?? parent::createStore();
    }

    protected function firstScreen(): Screen
    {
        $this->recorder->calls[] = 'firstScreen';
        return $this->first ?? new LoggedScreen('Home');
    }

    protected function start(): void
    {
        $this->recorder->calls[] = 'start';
    }

    protected function title(): string
    {
        return 'Gallery';
    }

    protected function width(): int
    {
        return 640;
    }

    protected function height(): int
    {
        return 480;
    }

    protected function linkOpened(string $url): void
    {
        $this->recorder->calls[] = "link $url";
    }

    protected function mainWindowClosed(): void
    {
        if ($this->onMainClosed !== null) {
            ($this->onMainClosed)();
            return;
        }
        parent::mainWindowClosed();
    }
}
