<?php

namespace Demo;

/** What AppLayout needs from the screen on top of the window. */
interface GalleryContext
{
    /** Shown in the title bar. */
    public function title(): string;
}
