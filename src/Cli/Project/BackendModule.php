<?php

declare(strict_types=1);

namespace RageKit\Cli\Project;

use RageKit\Cli\RageKitException;
use Symfony\Component\Yaml\Yaml;

/**
 * A backend module, src/<Name>/backend.yml. Its tpc settings are merged into the app; the
 * `ragekit:` section holds what only ragekit handles. `platforms: { windows: …, linux: … }`
 * holds per-OS additions (tpc keys and `ragekit:` keys), overlaid onto the rest for the
 * current OS (lists appended, scalars replaced; see ConfigMerger::forPlatform).
 *
 *   variables: { NAME: default }              defaults for ${NAME} (environment wins); ragekit
 *                                              adds RAGEKIT_TOOLCHAIN, the `ragekit setup` folder
 *   options:   { name: { values, define } }   app's `ragekit: name: value` → define
 *   copy:      [{ from, to }]                 runtime files next to the exe
 *   views:     { generator, … }                class turning templates into code (+ its own keys)
 *   libraries: [{ name, std, sources, include-paths, link-paths, link-libs }]
 *                                              native libraries built before tpc; `@generated`
 *                                              = build/<backend>/generated
 *   dev:       { shell: { std, defines, sources, include-paths, …, copy }, runtime }   `ragekit dev`
 */
final class BackendModule
{
    /**
     * @param array<string, mixed>                                $config  tpc settings
     * @param array<string, array{values: string[], define: string}> $options
     * @param list<array{from: string, to: string}>               $copies
     * @param array<string, string>                               $views
     * @param list<array<string, mixed>>                          $libraries
     */
    private function __construct(
        public readonly string $name,
        public readonly string $file,
        public readonly string $rageKitRoot,
        public readonly array $config,
        public readonly array $options,
        public readonly array $copies,
        public readonly array $views = [],
        public readonly array $libraries = [],
        public readonly array $dev = [],
    ) {
    }

    /**
     * Finds src/<Name>/backend.yml; $name matches the folder name case-insensitively.
     *
     * @param string $toolchainDir where `ragekit setup` installs (${RAGEKIT_TOOLCHAIN})
     */
    public static function find(string $rageKitRoot, string $name, string $toolchainDir): self
    {
        $available = [];
        foreach (glob("$rageKitRoot/src/*/backend.yml") ?: [] as $file) {
            $module = strtolower(basename(dirname($file)));
            $available[] = $module;
            if ($module === strtolower($name)) {
                return self::load($module, Paths::normalize($file), $rageKitRoot, $toolchainDir);
            }
        }
        throw new RageKitException("unknown backend '$name' (available: " . implode(', ', $available) . ')');
    }

    private static function load(string $name, string $file, string $rageKitRoot, string $toolchainDir): self
    {
        $raw = Yaml::parseFile($file) ?? [];
        $raw = ConfigMerger::forPlatform($raw, $file);
        $config = ConfigMerger::expandVars($raw, ['RAGEKIT_TOOLCHAIN' => Paths::normalize($toolchainDir)] + ($raw['ragekit']['variables'] ?? []));
        $ragekit = $config['ragekit'] ?? [];
        unset($config['ragekit']);

        return new self(
            $name,
            $file,
            $rageKitRoot,
            $config,
            $ragekit['options'] ?? [],
            $ragekit['copy'] ?? [],
            $ragekit['views'] ?? [],
            $ragekit['libraries'] ?? [],
            $ragekit['dev'] ?? [],
        );
    }

    /** A path inside RageKit (backend.yml paths are relative to the RageKit root). */
    public function path(string $relative): string
    {
        return Paths::absolute($relative, $this->rageKitRoot);
    }
}
