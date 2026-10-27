<?php

namespace RageKit\Notifications;

use RageKit\Events;
use RageKit\Modules;

/**
 * A desktop notification (notifications module). Clicking it runs $onClick on the UI thread, like a
 * click in a window; the windows show the new values afterwards.
 *
 *     Notification::show('Bob', 'Are you coming?', function (): void {
 *         $this->openConversation('bob');   // whatever the click should do
 *     });
 *
 * Linux: the desktop's notification service (GNOME, KDE, …). Windows: toast notifications. macOS: not yet.
 */
final class Notification
{
    private bool $open = true;

    private function __construct(public readonly int $id)
    {
    }

    /** Whether notifications can be shown here: the module's library and a notification service. */
    public static function available(): bool
    {
        return self::why() === '';
    }

    /** Why notifications can't be shown ('' when they can). */
    public static function why(): string
    {
        if (!Modules::available('notifications')) {
            return 'the notifications module isn\'t available: ' . \ragekit_module_error('notifications');
        }
        return \notifications_available();
    }

    /**
     * Shows a notification. The body is plain text. $onClick runs when the user clicks it, $onClose
     * when it goes away (clicked, dismissed, expired or closed by the app). With $onReply it has a
     * reply field ($replyHint is its placeholder) where the desktop offers one (Windows, KDE):
     * $onReply(string $text) runs with what the user sent.
     *
     * @throws \RuntimeException when it couldn't be shown (see why())
     */
    public static function show(string $title, string $body = '', ?\Closure $onClick = null, ?\Closure $onClose = null,
        ?\Closure $onReply = null, string $replyHint = 'Reply'): Notification
    {
        Modules::require('notifications');
        $id = \notifications_show($title, $body, $onReply !== null ? ($replyHint !== '' ? $replyHint : 'Reply') : '');
        if ($id === 0) {
            throw new \RuntimeException('the notification couldn\'t be shown: ' . \notifications_error());
        }
        $notification = new Notification($id);
        if ($onClick !== null) {
            Events::on('notifications', $id, 'clicked', function (string $data) use ($onClick): void {
                $onClick();
            });
        }
        if ($onReply !== null) {
            Events::on('notifications', $id, 'replied', function (string $data) use ($onReply): void {
                $onReply($data);
            });
        }
        Events::on('notifications', $id, 'closed', function (string $data) use ($notification, $onClose, $id): void {
            $notification->open = false;
            Events::off('notifications', $id);
            if ($onClose !== null) {
                $onClose();
            }
        });
        return $notification;
    }

    /**
     * The number on the app's taskbar button (Windows) or dock icon (KDE, Ubuntu's dock, Dash to
     * Dock: the Unity launcher API); 0 takes it away. False when it can't be shown (see badgeWhy()).
     */
    public static function badge(int $count): bool
    {
        return self::badgeWhy($count) === '';
    }

    /** Sets the badge (see badge()); '' when it's shown, otherwise why not. */
    public static function badgeWhy(int $count): string
    {
        if (!Modules::available('notifications')) {
            return 'the notifications module isn\'t available: ' . \ragekit_module_error('notifications');
        }
        return \notifications_badge(max(0, $count));
    }

    /** Whether it's still shown (as far as the app heard). */
    public function open(): bool
    {
        return $this->open;
    }

    /** Takes it away; onClose runs when the desktop confirms. */
    public function close(): void
    {
        if ($this->open) {
            \notifications_close($this->id);
        }
    }
}
