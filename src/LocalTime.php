<?php

namespace RageKit;

/**
 * Times in the computer's own timezone. PHP's date() in an app uses UTC (the app's PHP has no
 * timezone database), so a server's "2026-10-26T12:00:00Z" would show as 12:00 everywhere:
 *
 *     LocalTime::format('H:i', strtotime($message['created_at']));   // 14:00 in Budapest (summer)
 */
final class LocalTime
{
    private static ?Backend $backend = null;

    /** @internal The App's constructor hands its backend over (null: none, in tests). */
    public static function attach(?Backend $backend): void
    {
        self::$backend = $backend;
    }

    /** Seconds east of UTC at unix time $at (daylight saving included); 0 before an App exists. */
    public static function offset(int $at): int
    {
        return self::$backend?->utcOffset($at) ?? 0;
    }

    /**
     * date()'s formats, in local time. The letters that describe the timezone itself (e I O P p T Z U)
     * aren't supported: they would describe UTC. Use offset() for those.
     *
     * @throws \InvalidArgumentException for those letters (unless escaped with \)
     */
    public static function format(string $format, int $at): string
    {
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] === '\\') {
                $i++; // an escaped letter is text
            } elseif (str_contains('eIOPpTZU', $format[$i])) {
                throw new \InvalidArgumentException("LocalTime::format(): '{$format[$i]}' isn't supported (it would describe UTC); use offset()");
            }
        }
        return gmdate($format, $at + self::offset($at));
    }
}
