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

    public function cacheDir(): string
    {
        return '/cache';
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

    /** @var list<string> */
    public array $links = [];

    /** What claimInstance() answers (false: another instance runs). */
    public bool $first = true;

    public string $registerError = '';

    public function setVisible(int $window, bool $visible): void
    {
        $this->calls[] = ($visible ? 'show ' : 'hide ') . $window;
    }

    public function raise(int $window): void
    {
        $this->calls[] = "raise $window";
    }

    public function links(): array
    {
        return $this->links;
    }

    public function claimInstance(): bool
    {
        $this->calls[] = 'claim';
        return $this->first;
    }

    /** What utcOffset() answers. */
    public int $offset = 0;

    public string $clipboard = '';
    /** The clipboard image's file name ('' = none). */
    public string $clipboardImage = '';

    public function copyText(string $text): void
    {
        $this->calls[] = 'copy ' . $text;
        $this->clipboard = $text;
    }

    public function pasteText(): string
    {
        return $this->clipboard;
    }

    public function pasteImage(string $folder): string
    {
        $this->calls[] = 'paste image into ' . $folder;
        return $this->clipboardImage === '' ? '' : $folder . '/' . $this->clipboardImage;
    }

    public function clipboardHasImage(): bool
    {
        return $this->clipboardImage !== '';
    }

    public function utcOffset(int $at): int
    {
        return $this->offset;
    }

    public function openUrl(string $url): string
    {
        $this->calls[] = "browse $url";
        return '';
    }

    public function registerLink(string $scheme): string
    {
        $this->calls[] = "register $scheme";
        return $this->registerError;
    }
}
