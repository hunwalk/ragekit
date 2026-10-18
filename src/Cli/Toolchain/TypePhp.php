<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

use RageKit\Cli\RageKitException;
use RageKit\Cli\Setup\Installer;
use RageKit\Cli\Setup\Pins;

/**
 * Where TypePHP is and how to start its compiler (tpc). Normally the checkout `ragekit setup`
 * installed into the toolchain folder (.ragekit/typephp), which must match RageKit's pins.
 * TYPEPHP_HOME overrides it with any release (a native tpc / tpc.exe) or source checkout, used
 * as is. A checkout's bin/tpc.php runs on the PHP running ragekit (8.4+).
 */
final class TypePhp
{
    /** @param list<string> $command starts tpc; arguments follow */
    private function __construct(
        public readonly string $home,
        public readonly array $command,
        public readonly bool $isCheckout,
    ) {
    }

    public static function locate(string $toolchainDir, Pins $pins): self
    {
        $windows = Platform::isWindows();
        $home = rtrim((string) getenv('TYPEPHP_HOME'), '\\/');
        if ($home === '') {
            $home = "$toolchainDir/typephp";
            if (!Installer::installed($pins->component('typephp'), $home)) {
                throw new RageKitException(is_dir($home)
                    ? "TypePHP in $home doesn't match this RageKit version: run `ragekit setup` again"
                    : "TypePHP isn't set up: run `ragekit setup` (or set TYPEPHP_HOME)");
            }
        }
        $native = $home . ($windows ? '\\tpc.exe' : '/tpc');
        if (is_file($native) && ($windows || is_executable($native))) {
            return new self($home, [$native], false);
        }
        if (is_file("$home/bin/tpc.php")) {
            if (PHP_VERSION_ID < 80400) {
                throw new RageKitException('TypePHP needs PHP 8.4+ to run bin/tpc.php (this is ' . PHP_VERSION . ')');
            }
            if (!is_dir("$home/vendor")) {
                throw new RageKitException("run `composer install` in $home first");
            }
            return new self($home, [PHP_BINARY, "$home/bin/tpc.php"], true);
        }
        throw new RageKitException("TypePHP not found in $home (set TYPEPHP_HOME to a TypePHP release or source checkout)");
    }

    /**
     * tpc settings this platform needs unless the app sets them. On Linux/macOS the app links
     * the host PHP (the one running ragekit), so tpc must target that version (it defaults to
     * 8.5). Nano builds compile their own minimal runtime and need no libphp.
     *
     * @return array<string, mixed>
     */
    public static function defaults(bool $nano): array
    {
        if (Platform::isWindows()) {
            return [];
        }
        $defaults = ['php-version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION];
        if (!$nano && getenv('PHP_HOME') === false && HostPhp::find() === null) {
            // No usable host libphp (Debian/Ubuntu: libphp8.4-embed, plus cmake): tpc's php-builder
            // compiles a private PHP once (~/.typephp/php-builder, ~10 minutes) and links it statically.
            $defaults['php-builder'] = ['extensions' => [], 'zts' => PHP_ZTS ? 'on' : 'off'];
        }
        return $defaults;
    }

    /** PHPX's source tree, which tpc builds against (null for a release tpc, which ships its own). */
    public function phpxDir(): ?string
    {
        if (!$this->isCheckout) {
            return null;
        }
        $phpx = rtrim((string) getenv('PHPX_HOME'), '/');
        return $phpx !== '' ? $phpx : "$this->home/vendor/swoole/phpx";
    }

    /**
     * Windows: PHPX as TypePHP's release ships it (the same headers as the checkout's, plus the
     * phpx.lib normal builds link), so the checkout needn't build PHPX with nmake. tpc run from
     * source also wants phpx.dll in PHPX_HOME/build; the release keeps it next to php8ts.dll, so it
     * is copied there once. Null when $phpHome holds no release.
     */
    public static function releasePhpx(string $phpHome): ?string
    {
        $phpx = "$phpHome/phpx";
        if (!is_file("$phpx/lib/phpx.lib") || !is_file("$phpHome/phpx.dll")) {
            return null;
        }
        if (!is_file("$phpx/build/phpx.dll")) {
            @mkdir("$phpx/build", 0777, true);
            copy("$phpHome/phpx.dll", "$phpx/build/phpx.dll");
        }
        return $phpx;
    }
}
