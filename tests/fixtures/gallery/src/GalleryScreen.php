<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

/** Posts with tags and pictures (arrays inside rows); the buttons add to the first post. */
final class GalleryScreen extends Screen
{
    public Posts $posts;

    public function __construct()
    {
        $this->posts = new Posts();
        $this->posts->add(new Post('First', ['red'], [App::resolve('assets/red.png')]));
        $this->posts->add(new Post('Second', [], []));
    }

    public static function template(): string
    {
        return 'templates/GalleryScreen.rk';
    }

    public function addPicture(): void
    {
        $post = $this->posts->get(0);
        if ($post instanceof Post) {
            $post->pictures[] = App::resolve('assets/blue.png');
            $post->tags[] = 'blue';
            echo 'pictures ' . count($post->pictures) . "\n";
        }
    }
}
