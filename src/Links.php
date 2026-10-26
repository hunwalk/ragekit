<?php

namespace RageKit;

/**
 * App links (`myapp://…`). An app declares its schemes in project.yml (`ragekit: { links: [myapp] }`);
 * it then runs as a single instance: launching it again (by a link, or plainly) hands over to the
 * running one, which gets App::linkOpened($url) and comes to the front. Registering the app as the
 * OS's handler for a scheme is up to the app, when it wants:
 *
 *     Links::register('myapp');   // e.g. on the sign-in screen, before opening the browser
 *
 * Built apps only (`ragekit run`); in `ragekit dev`, register() throws.
 */
final class Links
{
    private static ?Backend $backend = null;

    /** @internal The App's constructor hands its backend over (null: none, in tests). */
    public static function attach(?Backend $backend): void
    {
        self::$backend = $backend;
    }

    /**
     * A link's query as name => value (`myapp://auth?code=a%20b&state=x` → code: "a b", state: "x"),
     * decoded like a form (+ is a space). The first of repeated names wins; names with [] aren't
     * arrays. Works in Nano builds, which have no parse_str().
     *
     * @return array<string, string>
     */
    public static function query(string $url): array
    {
        $start = strpos($url, '?');
        if ($start === false) {
            return [];
        }
        $end = strpos($url, '#', $start);
        $query = $end === false ? substr($url, $start + 1) : substr($url, $start + 1, $end - $start - 1);
        $out = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $name = rawurldecode(str_replace('+', ' ', $eq === false ? $pair : substr($pair, 0, $eq)));
            $value = $eq === false ? '' : rawurldecode(str_replace('+', ' ', substr($pair, $eq + 1)));
            if ($name !== '' && !isset($out[$name])) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Opens an http(s) URL in the user's browser (Linux: xdg-open, which honours $BROWSER and the
     * desktop's default; Windows: the default browser). Any app can do this, links or not.
     *
     * @throws \InvalidArgumentException for anything but http:// and https://
     * @throws \RuntimeException when the browser couldn't be started
     */
    public static function openInBrowser(string $url): void
    {
        $lower = strtolower($url);
        if (!str_starts_with($lower, 'http://') && !str_starts_with($lower, 'https://')) {
            throw new \InvalidArgumentException('openInBrowser() opens http:// and https:// URLs');
        }
        $backend = self::$backend ?? throw new \LogicException('the browser is opened once an App exists');
        $error = $backend->openUrl($url);
        if ($error !== '') {
            throw new \RuntimeException("couldn't open the browser: $error");
        }
    }

    /** @return list<string> the schemes the app declared */
    public static function schemes(): array
    {
        return self::$backend?->links() ?? [];
    }

    /**
     * Makes this executable the OS's handler for $scheme: links (Linux: a .desktop file and the
     * default in mimeapps.list; Windows: HKCU\Software\Classes). Running it again just refreshes it.
     *
     * @throws \InvalidArgumentException when project.yml doesn't declare the scheme
     * @throws \RuntimeException when the OS settings couldn't be written
     */
    public static function register(string $scheme): void
    {
        $backend = self::$backend ?? throw new \LogicException('links are registered once an App exists');
        if (!in_array($scheme, $backend->links(), true)) {
            throw new \InvalidArgumentException("'$scheme' isn't one of the app's links: declare it in project.yml (ragekit: { links: [$scheme] })");
        }
        $error = $backend->registerLink($scheme);
        if ($error !== '') {
            throw new \RuntimeException("couldn't register $scheme: links: $error");
        }
    }
}
