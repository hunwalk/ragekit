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

        public static function reset(): void
        {
            self::$calls = [];
            self::$dispatcher = null;
            self::$eventDispatcher = null;
            self::$nextWindow = 1;
            self::$modules = ['audio' => ''];
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
    function slint_close(int $window): void { FakeHost::$calls[] = "close $window"; }
    function slint_run(): void { FakeHost::$calls[] = 'run'; }
    function slint_quit(): void { FakeHost::$calls[] = 'quit'; }
    function slint_set_event_dispatcher(mixed $fn): void { FakeHost::$eventDispatcher = $fn; }
    function slint_app_dir(): string { return '/app'; }
    function ragekit_module_available(string $name): bool { return (FakeHost::$modules[$name] ?? 'not installed') === ''; }
    function ragekit_module_error(string $name): string { return FakeHost::$modules[$name] ?? 'not installed'; }
}
