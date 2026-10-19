<?php

declare(strict_types=1);

// Dev mode's \slint_* functions (same signatures as native/slint/host/slint.stub.php): they
// talk to the dev shell over fds 3/4 instead of calling libragekit_slint.

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

function ragekit_module_available(string $name): bool
{
    return (Session::channel()->request('module', $name)[1] ?? '0') === '1';
}

function ragekit_module_error(string $name): string
{
    return Session::channel()->request('module-error', $name)[1] ?? '';
}

function slint_open(string $screen, string $title, int $width, int $height): int
{
    $reply = Session::channel()->request('open', $screen, $title, (string) $width, (string) $height);
    $window = (int) ($reply[1] ?? 0);
    Session::opened($window, $title, $width, $height);
    Session::snapshot();
    return $window;
}

function slint_set_string(int $window, int $id, string $value): void
{
    Session::channel()->send('set-string', (string) $window, "p$id", $value);
}

function slint_set_int(int $window, int $id, int $value): void
{
    Session::channel()->send('set-int', (string) $window, "p$id", (string) $value);
}

function slint_set_float(int $window, int $id, float $value): void
{
    Session::channel()->send('set-float', (string) $window, "p$id", sprintf('%.17g', $value));
}

function slint_set_bool(int $window, int $id, bool $value): void
{
    Session::channel()->send('set-bool', (string) $window, "p$id", $value ? '1' : '0');
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
    Session::channel()->send('set-list', (string) $window, "p$id", ...$items);
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
