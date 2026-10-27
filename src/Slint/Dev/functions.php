<?php

declare(strict_types=1);

// Dev mode's \slint_* functions (same signatures as native/slint/host/slint.stub.php): they
// talk to the dev shell over a loopback connection (Channel) instead of calling libragekit_slint.

use RageKit\Slint\Dev\Session;

function slint_set_dispatcher(mixed $dispatcher): void
{
    Session::boot();
    Session::setDispatcher($dispatcher);
}

function slint_set_event_dispatcher(mixed $dispatcher): void
{
    Session::boot();
    Session::setEventDispatcher($dispatcher);
}

/** Dev mode: the project's folder (assets are read in place). */
function slint_app_dir(): string
{
    return (string) (getenv('RAGEKIT_APP_DIR') ?: getcwd());
}

function slint_print_error(string $line): void
{
    fwrite(STDERR, $line . "\n");
}

function ragekit_module_available(string $name): bool
{
    return (Session::channel()->request('module', $name)[1] ?? '0') === '1';
}

function ragekit_module_error(string $name): string
{
    return Session::channel()->request('module-error', $name)[1] ?? '';
}

/** App links are for built apps (`ragekit run`): in dev mode the app declares none and runs normally. */
function slint_links(): string
{
    return '';
}

function slint_links_claim(string $schemes): int
{
    return 1;
}

function slint_links_register(string $scheme): string
{
    return 'links work in built apps (ragekit run), not in ragekit dev';
}

function slint_window_raise(int $window, string $title): void
{
}

function slint_window_set_visible(int $window, bool $visible): void
{
    Session::channel()->send($visible ? 'show' : 'hide', (string) $window);
}

/** Dev mode runs full PHP: the system's timezone ($TZ, else /etc/localtime), read by PHP itself. */
function slint_utc_offset(int $at): int
{
    $zone = (string) getenv('TZ');
    if ($zone === '' && is_link('/etc/localtime')) {
        $zone = (string) preg_replace('#^.*/zoneinfo/#', '', (string) readlink('/etc/localtime'));
    }
    try {
        return (new \DateTimeZone($zone !== '' ? ltrim($zone, ':') : 'UTC'))->getOffset(new \DateTimeImmutable('@' . $at));
    } catch (\Exception $e) {
        return 0;
    }
}

/** The shell's window copies (as in a built app). */
function slint_clipboard_set(string $text): void
{
    Session::channel()->send('clipboard-set', $text);
}

function slint_clipboard_get(): string
{
    $reply = Session::channel()->request('clipboard-get');
    return $reply[1] ?? '';
}

/** The shell reads the clipboard's image (as in a built app). */
function slint_clipboard_image(string $folder): string
{
    $reply = Session::channel()->request('clipboard-image', $folder);
    return $reply[1] ?? '';
}

function slint_clipboard_has_image(): bool
{
    $reply = Session::channel()->request('clipboard-has-image');
    return ($reply[1] ?? '') === '1';
}

/** Dev mode runs full PHP: it opens the browser itself. */
function slint_open_url(string $url): string
{
    $command = PHP_OS_FAMILY === 'Windows' ? 'start "" ' . escapeshellarg($url) : 'xdg-open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &';
    exec($command, $output, $code);
    return $code === 0 ? '' : "couldn't open the browser";
}

/** The shell runs the timer and sends its ticks as "@timer" module events. */
function slint_timer_start(int $id, int $milliseconds, bool $repeat): void
{
    Session::channel()->send('timer-start', (string) $id, (string) $milliseconds, $repeat ? '1' : '0');
}

function slint_timer_stop(int $id): void
{
    Session::channel()->send('timer-stop', (string) $id);
}

function slint_open(string $screen, string $title, int $width, int $height): int
{
    $reply = Session::channel()->request('open', $screen, $title, (string) $width, (string) $height);
    $window = (int) ($reply[1] ?? 0);
    $GLOBALS['__ragekit_dev_components'][$window] = $screen;
    Session::opened($window, $title, $width, $height);
    Session::snapshot();
    return $window;
}

function slint_set_string(int $window, int $id, string $value): void
{
    Session::channel()->send('set-string', (string) $window, Session::property($id), $value);
}

function slint_set_int(int $window, int $id, int $value): void
{
    Session::channel()->send('set-int', (string) $window, Session::property($id), (string) $value);
}

function slint_set_float(int $window, int $id, float $value): void
{
    Session::channel()->send('set-float', (string) $window, Session::property($id), sprintf('%.17g', $value));
}

/** Dev mode never embeds registered images: they're read from the project like any file. */
function slint_set_image_asset(int $window, int $id, int $index): void
{
}

/** The shell loads the file (and reports one it can't decode); here: whether the file exists. */
function slint_set_image_file(int $window, int $id, string $path): bool
{
    Session::channel()->send('set-image', (string) $window, Session::property($id), $path);
    return $path === '' || is_file($path);
}

/** As the bridge's rk_cache_dir(), named after the project folder. */
function slint_cache_dir(): string
{
    $home = (string) (getenv('HOME') ?: '.');
    $base = match (PHP_OS_FAMILY) {
        'Windows' => (string) (getenv('LOCALAPPDATA') ?: '.'),
        'Darwin' => "$home/Library/Caches",
        default => (string) (getenv('XDG_CACHE_HOME') ?: "$home/.cache"),
    };
    $dir = $base . '/' . basename(slint_app_dir()) . '/images';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function slint_set_bool(int $window, int $id, bool $value): void
{
    Session::channel()->send('set-bool', (string) $window, Session::property($id), $value ? '1' : '0');
}

/** @var array{0: int, 1: int, 2: list<string>}|null the list being pushed: window, id, items */
$GLOBALS['__ragekit_dev_list'] = null;

function slint_list_begin(int $window, int $id): void
{
    $GLOBALS['__ragekit_dev_list'] = [$window, $id, []];
}

function slint_list_append(string $item): void
{
    $GLOBALS['__ragekit_dev_list'][2][] = $item;
}

function slint_list_end(): void
{
    [$window, $id, $items] = $GLOBALS['__ragekit_dev_list'];
    $GLOBALS['__ragekit_dev_list'] = null;
    Session::channel()->send('set-list', (string) $window, Session::property($id), ...$items);
}

/** @var array<int, string> window id => its window component (rows need its value types) */
$GLOBALS['__ragekit_dev_components'] = [];

/** @var array{0: int, 1: int, 2: list<list<array{0: string, 1: string}>>}|null the rows being pushed: window, id, rows of [type, text] cells */
$GLOBALS['__ragekit_dev_rows'] = null;

function slint_rows_begin(int $window, int $id): void
{
    $GLOBALS['__ragekit_dev_rows'] = [$window, $id, []];
}

function slint_rows_next(): void
{
    $GLOBALS['__ragekit_dev_rows'][2][] = [];
}

function ragekit_dev_cell(string $type, string $text): void
{
    $rows = &$GLOBALS['__ragekit_dev_rows'][2];
    $rows[count($rows) - 1][] = [$type, $text];
}

function slint_rows_string(string $value): void
{
    ragekit_dev_cell('s', $value);
}

function slint_rows_int(int $value): void
{
    ragekit_dev_cell('i', (string) $value);
}

function slint_rows_float(float $value): void
{
    ragekit_dev_cell('f', sprintf('%.17g', $value));
}

function slint_rows_bool(bool $value): void
{
    ragekit_dev_cell('b', $value ? '1' : '0');
}

/** Dev mode never embeds registered images (assetIndex() is -1 there): never called. */
function slint_rows_image_asset(int $index): void
{
    ragekit_dev_cell('img', '');
}

/** The shell loads the file and reports one it can't decode. */
function slint_rows_image_file(string $path): bool
{
    ragekit_dev_cell('img', $path);
    return true;
}

/**
 * One line: set-rows, window, property, struct|images (a collection, or a list shown as images),
 * the row and column counts, the column types, then the cells row by row (rows may have no
 * columns: a loop that reads no members).
 */
function slint_rows_end(): void
{
    [$window, $id, $rows] = $GLOBALS['__ragekit_dev_rows'];
    $GLOBALS['__ragekit_dev_rows'] = null;
    $component = $GLOBALS['__ragekit_dev_components'][$window] ?? '';
    $mode = Session::bindings()->type($component, $id) === 'images' ? 'images' : 'struct';
    $types = array_map(fn(array $cell) => $cell[0], $rows[0] ?? []);
    $cells = [];
    foreach ($rows as $row) {
        foreach ($row as [, $text]) {
            $cells[] = $text;
        }
    }
    Session::channel()->send('set-rows', (string) $window, Session::property($id), $mode, (string) count($rows), (string) count($types), ...$types, ...$cells);
}

function slint_close(int $window): void
{
    Session::channel()->send('close', (string) $window);
    Session::closed($window);
}

function slint_window_minimize(int $window): void
{
    Session::channel()->send('minimize', (string) $window);
}

function slint_window_set_maximized(int $window, bool $maximized): void
{
    Session::channel()->send('maximize', (string) $window, $maximized ? '1' : '0');
}

function slint_quit(): void
{
    Session::quit();
    Session::channel()->send('quit');
}

/** The event loop: reopens windows from before a restart, then handles the shell's messages. */
function slint_run(): void
{
    $app = Session::app();
    if ($app !== null) {
        $problems = Session::reopenLeftovers(function (object $screen, string $title, int $width, int $height) use ($app): void {
            $app->open($screen, $title, $width, $height);
        });
        foreach ($problems as $problem) {
            fwrite(STDERR, "ragekit dev: $problem\n");
        }
    }
    Session::channel()->send('run');
    Session::markRunning();
    Session::snapshot();
    while (!Session::quitting() && ($message = Session::channel()->next()) !== null) {
        if ($message[0] === 'reload') {
            Session::reloadBindings();
            $backend = Session::app()?->backend();
            if ($backend !== null && method_exists($backend, 'resync')) {
                $backend->resync();
            }
        } elseif ($message[0] === 'module-event') {
            $events = Session::eventDispatcher();
            if ($events !== null) {
                $events($message[1] ?? '', (int) ($message[2] ?? 0), $message[3] ?? '', $message[4] ?? '');
            }
        } elseif ($message[0] === 'event') {
            $dispatcher = Session::dispatcher();
            if ($dispatcher !== null) {
                $dispatcher((int) $message[1], (int) $message[2], $message[3] ?? '');
            }
        }
        Session::snapshot();
    }
}
