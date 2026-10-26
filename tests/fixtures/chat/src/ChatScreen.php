<?php

namespace Fix;

use RageKit\Screen;

/**
 * Messages 71-100 at first; scrolling near the top loads ten older ones (down to 1), anchored at the
 * bottom so the view doesn't jump; Receive adds a newer one. Prints what happens (tests/e2e/scroll.sh).
 */
final class ChatScreen extends Screen
{
    public Messages $messages;

    public string $anchor = 'top';

    public string $status = '';

    public string $draft = '';

    private int $oldest = 71;

    private int $newest = 100;

    public function __construct()
    {
        $this->messages = new Messages();
        for ($id = $this->oldest; $id <= $this->newest; $id++) {
            $this->messages->add(new Message($id, "Message $id"));
        }
        $this->status = '30 messages';
    }

    public static function template(): string
    {
        return 'templates/ChatScreen.rk';
    }

    public function loadOlder(): void
    {
        if ($this->oldest === 1) {
            echo "no older\n";
            return;
        }
        $older = [];
        for ($id = $this->oldest - 10; $id < $this->oldest; $id++) {
            $older[] = new Message($id, "Message $id");
        }
        $this->oldest -= 10;
        $this->anchor = 'bottom';
        $this->messages = new Messages([...$older, ...$this->messages->all()]);
        $this->status = count($this->messages) . ' messages';
        echo 'loaded ' . $this->oldest . "\n";
    }

    /** Return in the message box: the draft becomes a message at the bottom, and the box empties. */
    public function send(): void
    {
        if ($this->draft === '') {
            return;
        }
        $this->newest++;
        $this->anchor = 'top';
        $this->messages->add(new Message($this->newest, $this->draft));
        $this->status = count($this->messages) . ' messages';
        echo 'sent ' . $this->draft . "\n";
        $this->draft = '';
    }

    public function receive(): void
    {
        $this->newest++;
        $this->anchor = 'top';
        $this->messages->add(new Message($this->newest, "Message $this->newest"));
        $this->status = count($this->messages) . ' messages';
        echo 'received ' . $this->newest . "\n";
    }
}
