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

function slint_close(int $window): void {}

/** Runs the Slint event loop until slint_quit(). */
function slint_run(): void {}

function slint_quit(): void {}

/** fn(string $module, int $handle, string $kind, string $data): void — events from native modules. */
function slint_set_event_dispatcher(mixed $dispatcher): void {}

/** The folder of the executable (the dev runtime answers with the project's folder). */
function slint_app_dir(): string {}

/** Whether the native module's library could be loaded (see modules/<name>). */
function ragekit_module_available(string $name): bool {}

/** Why it couldn't ("" if it could). */
function ragekit_module_error(string $name): string {}
