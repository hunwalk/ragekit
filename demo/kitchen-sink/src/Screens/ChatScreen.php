<?php

namespace Demo\Screens;

use Demo\Chat\Message;
use Demo\Chat\Messages;
use RageKit\LocalTime;
use RageKit\Timer;

/**
 * A chat: a ScrollView that stays at the bottom, a box that grows (Enter sends, Shift+Enter adds a
 * line, Escape empties it), a bot that answers on a timer, and a menu on each bubble.
 */
final class ChatScreen extends Page
{
    private const REPLIES = [
        'Compiled PHP, drawn by Slint. No Chromium in here.',
        'Try right-clicking one of my messages.',
        'Shift+Enter adds a line, by the way.',
        'Scroll up: the view stays put while new messages arrive.',
    ];

    public const STYLES = [
        'row' => ['flexDirection' => 'row', 'justifyContent' => 'flex-start', 'paddingRight' => 64],
        'mineRow' => ['justifyContent' => 'flex-end', 'paddingLeft' => 64, 'paddingRight' => 0],
        'bubble' => ['backgroundColor' => 'rgba(127, 127, 127, 0.14)', 'borderRadius' => 14, 'paddingHorizontal' => 12,
            'paddingVertical' => 6, 'gap' => 2, 'maxWidth' => 420, 'borderWidth' => 1, 'borderColor' => 'transparent', 'transition' => 120],
        'mine' => ['backgroundColor' => '#1d4ed8'],
        'hot' => ['borderColor' => 'rgba(127, 127, 127, 0.6)'],
    ];

    public Messages $messages;
    public string $draft = '';
    public string $status = '';
    public string $anchor = 'top';
    public int $hovered = 0;
    private int $next = 1;
    private int $replies = 0;

    public function __construct()
    {
        $this->messages = new Messages();
        $this->say('Hi! I\'m RageBot.', false);
        $this->say('Wait, is this window PHP?', true);
        $this->say('Compiled PHP, drawn by Slint. No Chromium in here.', false);
        $this->say('Long messages wrap inside their bubble, and the list stays at the bottom while new ones arrive. Scroll up and it stays where you are.', false);
        $this->say('Nice. What else?', true);
        $this->say('Right-click a message for its menu, or hover one. Say something!', false);
    }

    public static function template(): string
    {
        return 'templates/Screens/Chat.rk';
    }

    /** Enter in the box. */
    public function send(): void
    {
        $text = trim($this->draft);
        if ($text === '') {
            return;
        }
        $this->draft = '';
        $this->say($text, true);
        $this->status = 'RageBot is typing…';
        Timer::after(0.8, function (): void {
            $this->status = '';
            $this->say(self::REPLIES[$this->replies++ % count(self::REPLIES)], false);
        });
    }

    /** Escape. */
    public function clearDraft(): void
    {
        $this->draft = '';
    }

    public function addMany(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->say('Message ' . $this->next . ' of a long chat', $i % 3 === 0);
        }
    }

    /** A bubble's menu: its text back into the box. */
    public function quote(int $id): void
    {
        foreach ($this->messages->all() as $message) {
            if ($message instanceof Message && $message->id === $id) {
                $this->draft = '> ' . $message->text . "\n";
            }
        }
    }

    public function remove(int $id): void
    {
        $kept = [];
        foreach ($this->messages->all() as $message) {
            if ($message instanceof Message && $message->id !== $id) {
                $kept[] = $message;
            }
        }
        $this->messages = new Messages($kept);
        $this->toast('Message deleted');
    }

    public function hoverIn(int $id): void
    {
        $this->hovered = $id;
    }

    public function hoverOut(): void
    {
        $this->hovered = 0;
    }

    private function say(string $text, bool $mine): void
    {
        $this->anchor = 'top';
        $this->messages->add(new Message($this->next++, $text, $mine, LocalTime::format('H:i', time())));
    }
}
