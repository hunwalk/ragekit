<?php

namespace Fix;

use RageKit\App;
use RageKit\Links;
use RageKit\Screen;

/** Registers itself for rktest: links, shows the last link it got, and quits on rktest://quit. */
final class LinksApp extends App
{
    private ResultScreen $screen;

    protected function firstScreen(): Screen
    {
        return $this->screen = new ResultScreen();
    }

    protected function title(): string
    {
        return 'links';
    }

    protected function start(): void
    {
        Links::register('rktest');
        echo "started\n";
    }

    protected function linkOpened(string $url): void
    {
        echo "link $url\n";
        foreach (Links::query($url) as $name => $value) {
            echo "  $name=$value\n";
        }
        $this->screen->text = $url;
        if ($url === 'rktest://quit') {
            $this->quit();
        }
    }
}
