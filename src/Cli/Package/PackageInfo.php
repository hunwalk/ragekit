<?php

declare(strict_types=1);

namespace RageKit\Cli\Package;

use RageKit\Cli\Project\Project;
use RageKit\Cli\RageKitException;

/**
 * What a package says about the app: project.yml `ragekit: { package: { version, description,
 * maintainer, homepage } }` (all optional), plus its name, links and icon.
 */
final class PackageInfo
{
    /** @param list<string> $links URL schemes (ragekit: links:) */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $maintainer,
        public readonly string $homepage,
        public readonly array $links,
    ) {
    }

    public static function of(Project $project): self
    {
        $package = $project->options['package'] ?? [];
        if (!is_array($package)) {
            throw new RageKitException('package must be a map, e.g. package: { version: 1.0.0, description: … }');
        }
        $version = (string) ($package['version'] ?? '0.0.0');
        if (preg_match('/^[0-9][A-Za-z0-9.+~-]*$/', $version) !== 1) {
            throw new RageKitException("package: version '$version' must start with a digit (letters, digits, . + ~ - after it)");
        }
        $links = array_values(array_map('strval', (array) ($project->options['links'] ?? [])));
        return new self(
            $project->name,
            $version,
            trim((string) ($package['description'] ?? $project->name . ', built with RageKit')),
            trim((string) ($package['maintainer'] ?? 'unknown <unknown@localhost>')),
            trim((string) ($package['homepage'] ?? '')),
            $links,
        );
    }

    /** The name as a Debian package name: lowercase letters, digits, + - . (at least two characters). */
    public function packageName(): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9+.-]+/', '-', $this->name));
        $name = trim($name, '-.+');
        return strlen($name) >= 2 ? $name : "app-$name";
    }
}
