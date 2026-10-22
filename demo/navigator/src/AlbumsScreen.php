<?php

namespace Demo;

use RageKit\Screen;

/** The first screen: a list of albums. Picking one shows its photos in the same window. */
final class AlbumsScreen extends Screen implements GalleryContext
{
    public const ALBUMS = ['Holiday', 'Family', 'Screenshots'];

    /** Kept while other screens are on top: still there after going back. */
    public string $last = 'none yet';

    public static function template(): string
    {
        return 'templates/Albums.rk';
    }

    public static function layout(): string
    {
        return AppLayout::class;
    }

    public function title(): string
    {
        return 'Albums';
    }

    public function show(string $album): void
    {
        $this->last = $album;
        $this->navigator()->push(new PhotosScreen($album));
    }
}
