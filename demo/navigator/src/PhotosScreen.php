<?php

namespace Demo;

use RageKit\Screen;

/** An album's photos. */
final class PhotosScreen extends Screen implements GalleryContext
{
    /** @var list<string> */
    public array $photos = [];

    public function __construct(public string $album = '')
    {
    }

    public static function template(): string
    {
        return 'templates/Photos.rk';
    }

    public static function layout(): string
    {
        return AppLayout::class;
    }

    public function title(): string
    {
        return $this->album;
    }

    /** Loads the photos when the screen appears (a real app would read a folder here). */
    public function shown(): void
    {
        $this->photos = [];
        for ($i = 1; $i <= 4; $i++) {
            $this->photos[] = "{$this->album} $i";
        }
    }

    public function open(string $photo): void
    {
        $this->navigator()->push(new PhotoScreen($photo));
    }
}
