<?php

declare(strict_types=1);

namespace RageKit\Cli\Setup;

use RageKit\Cli\Project\ConfigMerger;
use RageKit\Cli\RageKitException;
use Symfony\Component\Yaml\Yaml;

/** toolchain/toolchain.yml: the exact toolchain versions this RageKit version builds with. */
final class Pins
{
    /** @param array<string, ToolchainComponent> $components */
    private function __construct(private readonly array $components)
    {
    }

    public static function forRageKit(string $rageKitRoot): self
    {
        return self::load("$rageKitRoot/toolchain/toolchain.yml");
    }

    public static function load(string $file, ?string $platform = null): self
    {
        if (!is_file($file)) {
            throw new RageKitException("$file not found");
        }
        $components = [];
        foreach (Yaml::parseFile($file) ?? [] as $name => $spec) {
            $spec = ConfigMerger::forPlatform($spec, "$file: $name", $platform);
            $components[$name] = ToolchainComponent::fromArray((string) $name, $spec, dirname($file));
        }
        return new self($components);
    }

    public function component(string $name): ToolchainComponent
    {
        return $this->components[$name] ?? throw new RageKitException("toolchain.yml has no $name");
    }

    /** @return array<string, ToolchainComponent> */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * Identifies this exact toolchain (every component installable on this platform): the name of
     * its folder in the per-user toolchain directory.
     */
    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->components as $name => $component) {
            if ($component->available()) {
                $parts[$name] = $component->fingerprint();
            }
        }
        ksort($parts);
        return substr(hash('sha256', (string) json_encode($parts)), 0, 12);
    }
}
