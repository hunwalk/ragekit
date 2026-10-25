<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint\Runtime {
    final class FakeHost
    {
        /** @var list<string> */
        public static array $calls = [];
        public static ?\Closure $dispatcher = null;
        public static int $nextWindow = 1;
        public static ?\Closure $eventDispatcher = null;
        /** @var array<string, string> available modules => '' ; missing => error text */
        public static array $modules = ['audio' => ''];
        /** @var list<array<string, mixed>> http_request / http_cancel calls */
        public static array $http = [];
        public static int $nextRequest = 1;
        /** @var list<array{url: string, file: string, done: \Closure}> Screens::fetchImage() calls */
        public static array $fetches = [];
        public static bool $httpModule = true;
        public static string $cacheDir = '';
        /** Files the fake bridge can't decode (besides paths containing "broken"). */
        public static array $unloadable = [];

        public static function reset(): void
        {
            self::$unloadable = [];
            self::$fetches = [];
            self::$httpModule = true;
            self::$cacheDir = '';
            self::$calls = [];
            self::$dispatcher = null;
            self::$eventDispatcher = null;
            self::$nextWindow = 1;
            self::$modules = ['audio' => ''];
            self::$http = [];
            self::$nextRequest = 1;
        }
    }
}

namespace {
    use RageKit\Tests\Slint\Runtime\FakeHost;

    function slint_set_dispatcher(mixed $fn): void { FakeHost::$dispatcher = $fn; }
    function slint_open(string $screen, string $title, int $width, int $height): int { FakeHost::$calls[] = "open $screen \"$title\" {$width}x$height"; return FakeHost::$nextWindow++; }
    function slint_set_string(int $window, int $id, string $value): void { FakeHost::$calls[] = "string $window $id $value"; }
    function slint_set_int(int $window, int $id, int $value): void { FakeHost::$calls[] = "int $window $id $value"; }
    function slint_set_float(int $window, int $id, float $value): void { FakeHost::$calls[] = "float $window $id $value"; }
    function slint_set_bool(int $window, int $id, bool $value): void { FakeHost::$calls[] = "bool $window $id " . ($value ? 'true' : 'false'); }
    function slint_list_begin(int $window, int $id): void { FakeHost::$calls[] = "list $window $id"; }
    function slint_list_append(string $item): void { FakeHost::$calls[] = "  item $item"; }
    function slint_list_end(): void { FakeHost::$calls[] = 'list end'; }
    function slint_rows_begin(int $window, int $id): void { FakeHost::$calls[] = "rows $window $id"; }
    function slint_rows_next(): void { FakeHost::$calls[] = '  row'; }
    function slint_rows_string(string $value): void { FakeHost::$calls[] = "  s $value"; }
    function slint_rows_int(int $value): void { FakeHost::$calls[] = "  i $value"; }
    function slint_rows_float(float $value): void { FakeHost::$calls[] = "  f $value"; }
    function slint_rows_bool(bool $value): void { FakeHost::$calls[] = '  b ' . ($value ? '1' : '0'); }
    function slint_rows_image_asset(int $index): void { FakeHost::$calls[] = "  asset $index"; }
    function slint_rows_image_file(string $path): bool { FakeHost::$calls[] = "  file $path"; return !str_contains($path, 'broken') && !in_array($path, FakeHost::$unloadable, true); }
    function slint_rows_end(): void { FakeHost::$calls[] = 'rows end'; }
    function slint_close(int $window): void { FakeHost::$calls[] = "close $window"; }
    function slint_window_minimize(int $window): void { FakeHost::$calls[] = "minimize $window"; }
    function slint_window_set_maximized(int $window, bool $maximized): void { FakeHost::$calls[] = "maximize $window " . ($maximized ? '1' : '0'); }
    function slint_run(): void { FakeHost::$calls[] = 'run'; }
    function slint_quit(): void { FakeHost::$calls[] = 'quit'; }
    function slint_timer_start(int $id, int $milliseconds, bool $repeat): void { FakeHost::$calls[] = "timer-start $id $milliseconds " . ($repeat ? '1' : '0'); }
    function slint_timer_stop(int $id): void { FakeHost::$calls[] = "timer-stop $id"; }
    function slint_set_event_dispatcher(mixed $fn): void { FakeHost::$eventDispatcher = $fn; }
    function slint_app_dir(): string { return '/app'; }
    function slint_print_error(string $line): void { FakeHost::$calls[] = "error $line"; }
    function slint_set_image_asset(int $window, int $id, int $index): void { FakeHost::$calls[] = "image-asset $window $id $index"; }
    function slint_set_image_file(int $window, int $id, string $path): bool { FakeHost::$calls[] = "image-file $window $id $path"; return !str_contains($path, 'broken') && !in_array($path, FakeHost::$unloadable, true); }
    function slint_cache_dir(): string { return FakeHost::$cacheDir; }
    function ragekit_module_available(string $name): bool { return (FakeHost::$modules[$name] ?? 'not installed') === ''; }
    function http_request(string $method, string $url, string $headers, string $body, string $file, float $timeout): int
    {
        FakeHost::$http[] = compact('method', 'url', 'headers', 'body', 'file', 'timeout');
        return FakeHost::$nextRequest++;
    }
    function http_cancel(int $request): void { FakeHost::$http[] = ['cancel' => $request]; }
    function ragekit_module_error(string $name): string { return FakeHost::$modules[$name] ?? 'not installed'; }
}
