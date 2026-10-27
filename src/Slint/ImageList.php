<?php

namespace RageKit\Slint;

/**
 * A row's array member shown as images (a @foreach inside the row's with <Image source="{{ $item }}">),
 * as the generated binder reads it: the image sources, each as ImageValue would hold one.
 */
final class ImageList
{
    /** @param list<string> $sources */
    public function __construct(public array $sources)
    {
    }
}
