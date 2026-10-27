<?php

namespace RageKit;

/**
 * Text that is only emoji, e.g. to show a chat message of one to three emoji big with <Emoji>
 * instead of as text:
 *
 *     @if (\RageKit\Emoji::only($m->body))
 *         <Emoji text="{{ $m->body }}" size="40" />
 *
 * Sequences count as one emoji: 👍🏽 (skin tone), 👩‍💻 (joined), 🇭🇺 (a flag), 1️⃣ (a keycap).
 */
final class Emoji
{
    /**
     * :shortcode: names → emoji: the ones <Text markdown="true"> turns into emoji (the same list as
     * native/slint/window/markdown.cc), for suggestions while typing.
     */
    public const SHORTCODES = [
        '+1' => '👍', '-1' => '👎', '100' => '💯', 'angry' => '😠', 'astonished' => '😲', 'awesome' => '😎',
        'bang' => '❗', 'beer' => '🍺', 'beers' => '🍻', 'blush' => '😊', 'boom' => '💥', 'broken_heart' => '💔',
        'bug' => '🐛', 'cake' => '🎂', 'camera' => '📷', 'cat' => '🐱', 'check' => '✔️', 'clap' => '👏',
        'coffee' => '☕', 'confused' => '😕', 'cool' => '🆒', 'cry' => '😢', 'dancer' => '💃',
        'disappointed' => '😞', 'dog' => '🐶', 'eyes' => '👀', 'facepalm' => '🤦', 'fire' => '🔥',
        'flushed' => '😳', 'frowning' => '😦', 'ghost' => '👻', 'gift' => '🎁', 'grimacing' => '😬',
        'grin' => '😁', 'grinning' => '😀', 'hand' => '✋', 'heart' => '❤️', 'heart_eyes' => '😍',
        'hearts' => '♥️', 'heavy_check_mark' => '✔️', 'hugs' => '🤗', 'hushed' => '😯', 'innocent' => '😇',
        'joy' => '😂', 'kiss' => '😘', 'kissing_heart' => '😘', 'laughing' => '😆', 'lol' => '😂', 'memo' => '📝',
        'metal' => '🤘', 'moneybag' => '💰', 'muscle' => '💪', 'neutral_face' => '😐', 'no_mouth' => '😶',
        'ok' => '🆗', 'ok_hand' => '👌', 'open_mouth' => '😮', 'partying_face' => '🥳', 'pensive' => '😔',
        'point_down' => '👇', 'point_left' => '👈', 'point_right' => '👉', 'point_up' => '☝️', 'poop' => '💩',
        'pray' => '🙏', 'punch' => '👊', 'question' => '❓', 'rage' => '😡', 'raised_hands' => '🙌',
        'relaxed' => '☺️', 'relieved' => '😌', 'rocket' => '🚀', 'rofl' => '🤣', 'rolling_eyes' => '🙄',
        'see_no_evil' => '🙈', 'shrug' => '🤷', 'skull' => '💀', 'sleeping' => '😴',
        'slightly_smiling_face' => '🙂', 'smile' => '😄', 'smiley' => '😃', 'smirk' => '😏', 'sob' => '😭',
        'sparkles' => '✨', 'star' => '⭐', 'star_struck' => '🤩', 'stuck_out_tongue' => '😛',
        'stuck_out_tongue_winking_eye' => '😜', 'sunglasses' => '😎', 'sweat' => '😓', 'sweat_smile' => '😅',
        'tada' => '🎉', 'thinking' => '🤔', 'thumbsdown' => '👎', 'thumbsup' => '👍', 'tired_face' => '😫',
        'trophy' => '🏆', 'unamused' => '😒', 'upside_down_face' => '🙃', 'v' => '✌️', 'warning' => '⚠️',
        'wave' => '👋', 'weary' => '😩', 'white_check_mark' => '✅', 'wink' => '😉', 'worried' => '😟',
        'x' => '❌', 'yum' => '😋', 'zany_face' => '🤪', 'zap' => '⚡', 'zipper_mouth_face' => '🤐', 'zzz' => '💤',
    ];

    /**
     * Shortcodes starting with $prefix (without the colon), as name => emoji, at most $limit, the
     * exact name first, then alphabetical: suggest('thu') → ['thumbsdown' => '👎', 'thumbsup' => '👍'].
     *
     * @return array<string, string>
     */
    public static function suggest(string $prefix, int $limit = 6): array
    {
        $prefix = strtolower($prefix);
        $found = [];
        if (isset(self::SHORTCODES[$prefix])) {
            $found[$prefix] = self::SHORTCODES[$prefix];
        }
        foreach (self::SHORTCODES as $name => $emoji) {
            if (count($found) >= $limit) {
                break;
            }
            if (str_starts_with((string) $name, $prefix)) {
                $found[(string) $name] = $emoji;
            }
        }
        return $found;
    }

    /** Whether the text is 1..$max emoji and nothing else (spaces between them are fine). */
    public static function only(string $text, int $max = 3): bool
    {
        $count = self::count($text);
        return $count >= 1 && $count <= $max;
    }

    /** How many emoji the text holds; -1 when there's anything else (letters, digits, punctuation). */
    public static function count(string $text): int
    {
        $count = 0;
        $joined = false;     // the last code point was a zero width joiner
        $regional = false;   // an unpaired regional indicator (half a flag) came last
        $pendingKeycap = false;
        $i = 0;
        $length = strlen($text);
        while ($i < $length) {
            $cp = self::next($text, $i);
            if ($pendingKeycap) {
                if ($cp === 0xFE0F) {
                    continue;
                }
                if ($cp !== 0x20E3) {
                    return -1; // a digit, #, * that isn't a keycap
                }
                $pendingKeycap = false;
                $count++;
                continue;
            }
            if ($cp === 0x20 || $cp === 0x09 || $cp === 0x0A || $cp === 0x0D) {
                $joined = false;
                continue;
            }
            if ($cp === 0x200D) {
                $joined = true;
                continue;
            }
            if ($cp === 0xFE0F || $cp === 0xFE0E || ($cp >= 0x1F3FB && $cp <= 0x1F3FF) || ($cp >= 0xE0020 && $cp <= 0xE007F)) {
                continue; // modifies the emoji before it
            }
            if (($cp >= 0x30 && $cp <= 0x39) || $cp === 0x23 || $cp === 0x2A) {
                $pendingKeycap = true;
                continue;
            }
            if ($cp >= 0x1F1E6 && $cp <= 0x1F1FF) {
                if ($regional) {
                    $regional = false; // the second half of a flag
                } else {
                    $regional = true;
                    $count++;
                }
                continue;
            }
            if (!self::pictographic($cp)) {
                return -1;
            }
            if (!$joined) {
                $count++;
            }
            $joined = false;
        }
        return $pendingKeycap ? -1 : $count;
    }

    private static function pictographic(int $cp): bool
    {
        return ($cp >= 0x1F000 && $cp <= 0x1FAFF) || ($cp >= 0x2190 && $cp <= 0x2BFF) || ($cp >= 0x2300 && $cp <= 0x23FF)
            || $cp === 0xA9 || $cp === 0xAE || $cp === 0x203C || $cp === 0x2049 || $cp === 0x2122 || $cp === 0x2139
            || $cp === 0x24C2 || $cp === 0x3030 || $cp === 0x303D || $cp === 0x3297 || $cp === 0x3299;
    }

    /** The code point at byte $i (advancing $i); -1 for broken UTF-8. */
    private static function next(string $text, int &$i): int
    {
        $c = ord($text[$i]);
        $extra = $c < 0x80 ? 0 : ($c >> 5 === 0x6 ? 1 : ($c >> 4 === 0xE ? 2 : ($c >> 3 === 0x1E ? 3 : -1)));
        if ($extra < 0 || $i + $extra >= strlen($text) + ($extra === 0 ? 1 : 0)) {
            $i++;
            return -1;
        }
        $cp = $extra === 0 ? $c : ($extra === 1 ? $c & 0x1F : ($extra === 2 ? $c & 0x0F : $c & 0x07));
        for ($k = 1; $k <= $extra; $k++) {
            $cp = ($cp << 6) | (ord($text[$i + $k]) & 0x3F);
        }
        $i += $extra + 1;
        return $cp;
    }
}
