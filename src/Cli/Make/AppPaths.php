<?php

declare(strict_types=1);

namespace RageKit\Cli\Make;

use RageKit\Cli\Project\Paths;
use RageKit\Cli\RageKitException;

/** Where an app keeps its classes (src/) and which namespace src/ is (composer.json's psr-4). */
final class AppPaths
{
    private function __construct(
        public readonly string $projectDir,
        public readonly string $srcDir,
        public readonly string $namespace,
        public readonly ?string $namespaceNote,
    ) {
    }

    public static function find(string $projectDir): self
    {
        $projectDir = rtrim(Paths::normalize($projectDir), '/');
        if (!is_file("$projectDir/project.yml")) {
            throw new RageKitException("run it in your app's folder (the one with project.yml)");
        }
        if (!is_dir("$projectDir/src")) {
            throw new RageKitException('no src/ folder here: make: puts classes under src/ (create it, and list it under sources: in project.yml)');
        }
        $namespace = self::namespace($projectDir);
        return new self($projectDir, "$projectDir/src", $namespace ?? 'App', $namespace === null ? 'no psr-4 entry for src/ in composer.json; using App\\' : null);
    }

    /** The psr-4 namespace composer.json maps to src/, without the trailing \ (null: none). */
    private static function namespace(string $projectDir): ?string
    {
        $composer = json_decode((string) @file_get_contents("$projectDir/composer.json"), true);
        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                if (trim(preg_replace('~^\./~', '', str_replace('\\', '/', (string) $dir)) ?? '', '/') === 'src') {
                    return trim((string) $prefix, '\\');
                }
            }
        }
        return null;
    }
}
