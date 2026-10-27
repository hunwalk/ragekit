<?php

namespace Fix;

final class Post
{
    /**
     * @param list<string> $tags
     * @param list<string> $pictures image files
     */
    public function __construct(public string $title = '', public array $tags = [], public array $pictures = [])
    {
    }
}
