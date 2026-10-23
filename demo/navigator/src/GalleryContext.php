<?php

namespace Demo;

/** What AppWindow needs from the screen on top of the window. */
interface GalleryContext
{
    /** Shown in the title bar. */
    public function title(): string;
}
