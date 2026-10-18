<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\Fetch\Dependency;
use RageKit\Cli\Project\ConfigMerger;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\RageKitException;
use Symfony\Component\Yaml\Yaml;

/**
 * A kit: Slint components plus a kit.yml mapping template tags to them. ${RAGEKIT} (RageKit
 * root) and ${KIT} (the kit's folder) are expanded in kit.yml.
 */
final class Kit
{
    /**
     * @param array<string, string>      $libraries Slint library name => path (slint-compiler -L)
     * @param array<string, ElementSpec> $elements  tag => spec
     * @param list<Dependency>           $fetch     third-party code it needs (kit.yml `fetch:`)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly string $entry,
        public readonly array $libraries,
        public readonly ?string $window,
        public readonly array $elements,
        public readonly array $fetch = [],
    ) {
    }

    public static function load(string $file, string $rageKitRoot): self
    {
        $real = realpath($file);
        if ($real === false) {
            throw new RageKitException("kit.yml not found: $file");
        }
        $dir = Paths::normalize(dirname($real));
        $raw = Yaml::parseFile($real);
        if (!is_array($raw)) {
            throw new RageKitException("$file: not a kit.yml");
        }
        // ${RAGEKIT} and ${KIT} are fixed paths: environment variables must not override them.
        $raw = ConfigMerger::expandVars(self::expandFixed($raw, ['RAGEKIT' => $rageKitRoot, 'KIT' => $dir]), []);
        $name = (string) ($raw['name'] ?? throw new RageKitException("$file: missing name"));
        $entry = (string) ($raw['entry'] ?? throw new RageKitException("$file: missing entry"));
        $elements = [];
        foreach ($raw['elements'] ?? [] as $tag => $spec) {
            $elements[(string) $tag] = ElementSpec::fromArray($spec, "$file: elements.$tag");
        }
        return new self(
            $name,
            $dir,
            "$dir/$entry",
            array_map('strval', $raw['libraries'] ?? []),
            isset($raw['window']) ? (string) $raw['window'] : null,
            $elements,
            Dependency::list($raw['fetch'] ?? [], $dir, $file),
        );
    }

    /**
     * @param array<string, string> $vars
     */
    private static function expandFixed(mixed $value, array $vars): mixed
    {
        if (is_array($value)) {
            return array_map(fn($v) => self::expandFixed($v, $vars), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/\$\{(RAGEKIT|KIT)\}/', fn(array $m) => $vars[$m[1]], $value);
    }
}
