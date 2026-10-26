<?php

declare(strict_types=1);

namespace RageKit\Cli\Build;

use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;

/**
 * project.yml `ragekit: { icon: assets/icon.png }`: the app's icon, a png (or svg) file. Windows
 * show it (the compiler sets every window's icon), it's copied next to the executable as
 * icon.<ext> (the .desktop file Links::register() writes names it), and on Windows a png becomes
 * the executable's own icon too (an .ico that wraps the png).
 */
final class AppIcon
{
    /** The icon's absolute path, or '' when the app has none. */
    public static function path(Project $project): string
    {
        $icon = $project->options['icon'] ?? '';
        if ($icon === '' || $icon === null) {
            return '';
        }
        if (!is_string($icon)) {
            throw new RageKitException('icon must be a file, e.g. icon: assets/icon.png');
        }
        $path = str_starts_with($icon, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $icon) === 1 ? $icon : $project->dir . '/' . $icon;
        if (!is_file($path)) {
            throw new RageKitException("icon: $icon doesn't exist");
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'svg'], true)) {
            throw new RageKitException("icon: $icon must be a .png or .svg file");
        }
        return (string) realpath($path);
    }

    /** An .ico holding the png as is (Windows Vista and later read png entries). */
    public static function ico(string $png): string
    {
        $data = (string) file_get_contents($png);
        if (!str_starts_with($data, "\x89PNG\r\n\x1a\n") || strlen($data) < 24) {
            throw new RageKitException("icon: $png isn't a png file");
        }
        $width = unpack('N', substr($data, 16, 4))[1];
        $height = unpack('N', substr($data, 20, 4))[1];
        // ICONDIR (reserved, type 1 = icon, 1 image) + ICONDIRENTRY (0 means 256 px) + the png.
        return pack('vvv', 0, 1, 1)
            . pack('CCCCvvVV', $width >= 256 ? 0 : $width, $height >= 256 ? 0 : $height, 0, 0, 1, 32, strlen($data), 6 + 16)
            . $data;
    }
}
