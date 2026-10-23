<?php

namespace Demo;

use RageKit\Screen;

/** One photo, which can be renamed in a dialog. */
final class PhotoScreen extends Screen implements GalleryContext
{
    public bool $renaming = false;
    public string $draft = '';

    public function __construct(public string $name = '')
    {
    }

    public static function template(): string
    {
        return 'templates/Photo.rk';
    }

    public static function window(): string
    {
        return AppWindow::class;
    }

    public function title(): string
    {
        return $this->name;
    }

    public function rename(): void
    {
        $this->draft = $this->name;
        $this->renaming = true;
    }

    public function cancel(): void
    {
        $this->renaming = false;
    }

    public function save(): void
    {
        $this->name = $this->draft;
        $this->renaming = false;
        $this->toast('Renamed');
    }

    /** Back to the albums, whatever is on the stack. */
    public function home(): void
    {
        $this->navigator()->reset(new AlbumsScreen());
    }
}
