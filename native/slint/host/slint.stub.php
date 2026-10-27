<?php

/**
 * Slint backend host (slint_host.cc), a thin layer over libragekit_slint. Windows show screens
 * compiled at build time from .rk templates and are addressed by integer ids (0 = invalid).
 */

/** fn(int $window, int $action, string $arg): void — action 0 means the window asks to close. */
function slint_set_dispatcher(mixed $dispatcher): void {}

/** Opens the compiled screen $screen; returns its window id, or 0 if it doesn't exist. */
function slint_open(string $screen, string $title, int $width, int $height): int {}

function slint_set_string(int $window, int $id, string $value): void {}

function slint_set_int(int $window, int $id, int $value): void {}

function slint_set_float(int $window, int $id, float $value): void {}

function slint_set_bool(int $window, int $id, bool $value): void {}

/** A list value is pushed as begin, one append per item, end. */
function slint_list_begin(int $window, int $id): void {}

function slint_list_append(string $item): void {}

function slint_list_end(): void {}

/**
 * Rows of a collection (or a list shown as images) are pushed as begin, then per row next() and
 * one call per cell in field order, then end. An image cell is a registered image or a file
 * ('' = none); image_file returns false if the file couldn't be loaded.
 */
function slint_rows_begin(int $window, int $id): void {}

function slint_rows_next(): void {}

function slint_rows_string(string $value): void {}

function slint_rows_int(int $value): void {}

function slint_rows_float(float $value): void {}

function slint_rows_bool(bool $value): void {}

function slint_rows_image_asset(int $index): void {}

function slint_rows_image_file(string $path): bool {}

function slint_rows_list_begin(): void {}

function slint_rows_list_end(): void {}

function slint_rows_end(): void {}

function slint_close(int $window): void {}

/** Minimises a window (layouts' minimize()). */
function slint_window_minimize(int $window): void {}

/** Whether a window is minimised (Window::minimized()). */
function slint_window_is_minimized(int $window): bool {}

function slint_window_set_maximized(int $window, bool $maximized): void {}

/** Starts timer $id (RageKit\Timer): a "@timer" event every $milliseconds, or once. */
function slint_timer_start(int $id, int $milliseconds, bool $repeat): void {}

function slint_timer_stop(int $id): void {}

/** The URL schemes the app declared (project.yml `ragekit: { links: [...] }`), comma-separated. */
function slint_links(): string {}

/** 1: this is the app's running instance; 0: this launch handed its link over (exit). */
function slint_links_claim(string $schemes): int {}

/** Makes the app the OS's handler for $scheme links; '' or the error. */
function slint_links_register(string $scheme): string {}

/** Puts $text on the clipboard. */
function slint_clipboard_set(string $text): void {}

/** The clipboard's text ('' if none). */
function slint_clipboard_get(): string {}

/** The clipboard's image saved into $folder; its path ('' if there's no image). */
function slint_clipboard_image(string $folder): string {}

/** Whether the clipboard holds an image. */
function slint_clipboard_has_image(): bool {}

/** Seconds east of UTC of the computer's local time at unix time $at. */
function slint_utc_offset(int $at): int {}

/** Opens $url in the user's browser; '' or the error. */
function slint_open_url(string $url): string {}

/** Hides window $window without closing it, or shows it again. */
function slint_window_set_visible(int $window, bool $visible): void {}

/** Brings window $window (titled $title) to the front. */
function slint_window_raise(int $window, string $title): void {}

/** Runs the Slint event loop until slint_quit(). */
function slint_run(): void {}

function slint_quit(): void {}

/** fn(string $module, int $handle, string $kind, string $data): void — events from native modules. */
function slint_set_event_dispatcher(mixed $dispatcher): void {}

/** The folder of the executable (the dev runtime answers with the project's folder). */
function slint_app_dir(): string {}

/** Shows registered image $index (RkAssets.list) in image property $id. */
function slint_set_image_asset(int $window, int $id, int $index): void {}

/** Loads $path into image property $id ('' clears it); false if it couldn't be loaded. */
function slint_set_image_file(int $window, int $id, string $path): bool {}

/** The app's image cache folder (created): <user cache>/<app>/images. */
function slint_cache_dir(): string {}

/** Writes $line and a newline to stderr (Nano PHP has no STDERR). */
function slint_print_error(string $line): void {}

/** Whether the native module's library could be loaded (see modules/<name>). */
function ragekit_module_available(string $name): bool {}

/** Why it couldn't ("" if it could). */
function ragekit_module_error(string $name): string {}
