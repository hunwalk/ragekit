<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\RageKitException;

/** All installed kits: RageKit's own (kits/*) plus Composer packages declaring extra.ragekit.kit. */
final class KitRegistry
{
    /** @param array<string, Kit> $kits */
    private function __construct(private readonly array $kits)
    {
    }

    public static function discover(string $rageKitRoot, string $projectDir): self
    {
        $files = glob("$rageKitRoot/kits/*/kit.yml") ?: [];
        // The app's own kits, e.g. <project>/kits/icons/kit.yml.
        $files = [...$files, ...(glob("$projectDir/kits/*/kit.yml") ?: [])];
        $installed = "$projectDir/vendor/composer/installed.json";
        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
            foreach ($packages as $package) {
                $kit = $package['extra']['ragekit']['kit'] ?? null;
                if (is_string($kit) && isset($package['install-path'])) {
                    $files[] = "$projectDir/vendor/composer/{$package['install-path']}/$kit";
                }
            }
        }
        return self::load($files, $rageKitRoot);
    }

    /** @param list<string> $files kit.yml paths */
    public static function load(array $files, string $rageKitRoot): self
    {
        $kits = [];
        $from = [];
        foreach ($files as $file) {
            $kit = Kit::load($file, $rageKitRoot);
            $path = realpath($file) ?: $file;
            // The same kit.yml found twice is fine; two files claiming one name aren't, even in one folder.
            if (isset($from[$kit->name]) && $from[$kit->name] !== $path) {
                throw new RageKitException("two kits are named {$kit->name}: {$from[$kit->name]} and $path");
            }
            $kits[$kit->name] = $kit;
            $from[$kit->name] = $path;
        }
        return new self($kits);
    }

    public function find(string $name): ?Kit
    {
        return $this->kits[$name] ?? null;
    }

    public function get(string $name): Kit
    {
        return $this->kits[$name]
            ?? throw new RageKitException("unknown kit '$name' (installed: " . implode(', ', $this->names()) . ')');
    }

    /**
     * The kits whose entry file app.slint imports, i.e. the ones templates use.
     *
     * @param list<string> $imports kit entry files (CompiledViews::$imports)
     * @return list<Kit>
     */
    public function used(array $imports): array
    {
        return array_values(array_filter($this->kits, fn(Kit $kit) => in_array($kit->entry, $imports, true)));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->kits);
    }
}
