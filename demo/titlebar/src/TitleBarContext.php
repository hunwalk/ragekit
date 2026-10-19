<?php

namespace Demo;

/** What AppLayout needs from the screen it frames. */
interface TitleBarContext
{
    /** Shown in the title bar. */
    public function title(): string;

    /** The bar's search box was submitted. */
    public function search(string $query): void;
}
