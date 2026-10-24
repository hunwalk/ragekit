<?php

namespace RageKit\Slint;

/**
 * A bound image source as the generated binder reads it: the string a template's
 * source="{{ … }}" shows (a registered asset's path, a file, an http(s) URL, or '').
 */
final class ImageValue
{
    public function __construct(public string $source)
    {
    }
}
