<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\RageKitException;

/**
 * The app's images as App::assets() registers them: groups of class constants holding paths.
 * Templates show them as $assets->group->name; every file is embedded, and index() is a path's
 * position in the embedded list (what a bound value switches to at runtime).
 */
final class AssetRegistry
{
    /**
     * @param array<string, array<string, array{0: string, 1: string}>> $groups group => template name => [constant value, absolute path]
     */
    private function __construct(private readonly array $groups)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param array<string, ClassInfo> $classes */
    public static function fromClasses(array $classes, string $projectDir): self
    {
        $apps = array_values(array_filter($classes, fn(ClassInfo $c) => $c->isApp && $c->assets !== []));
        if ($apps === []) {
            return self::empty();
        }
        if (count($apps) > 1) {
            throw new RageKitException('two apps register assets (' . implode(', ', array_map(fn(ClassInfo $c) => $c->name, $apps)) . '); keep one');
        }
        $app = $apps[0];
        $groups = [];
        foreach ($app->assets as $entry) {
            $group = $entry['group'];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $group) !== 1) {
                throw new RageKitException("{$app->short}::assets(): '$group' is not a valid name");
            }
            if (!$entry['found']) {
                throw new RageKitException("{$app->short}::assets(): '$group' => {$entry['class']}: class not found");
            }
            $names = [];
            $constants = [];
            foreach ($entry['constants'] as $constant => $value) {
                $where = "{$entry['class']}::$constant";
                if (!is_string($value)) {
                    throw new RageKitException("$where: expected a path (string)");
                }
                $absolute = preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $value) === 1 ? $value : rtrim($projectDir, '/\\') . '/' . $value;
                if (!is_file($absolute)) {
                    throw new RageKitException("$where: $value doesn't exist");
                }
                $name = self::templateName((string) $constant);
                if (isset($constants[$name])) {
                    throw new RageKitException("{$entry['class']}: {$constants[$name]} and $constant are both \$assets->$group->$name in templates");
                }
                $constants[$name] = (string) $constant;
                $names[$name] = [$value, str_replace('\\', '/', $absolute)];
            }
            $groups[$group] = $names;
        }
        return new self($groups);
    }

    /** LOGO → logo, APP_LOGO → appLogo. */
    public static function templateName(string $constant): string
    {
        $parts = explode('_', strtolower($constant));
        $name = array_shift($parts);
        foreach ($parts as $part) {
            $name .= ucfirst($part);
        }
        return $name;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return array_keys($this->groups);
    }

    /** @return list<string> template names in $group */
    public function names(string $group): array
    {
        return array_keys($this->groups[$group] ?? []);
    }

    /** The absolute path of $assets->$group->$name, or null. */
    public function find(string $group, string $name): ?string
    {
        return $this->groups[$group][$name][1] ?? null;
    }

    /** @return list<string> the constants' values, in registration order, each once */
    public function paths(): array
    {
        return array_keys($this->entries());
    }

    /** @return list<string> absolute paths, in the same order as paths() */
    public function absolutePaths(): array
    {
        return array_values($this->entries());
    }

    /** $value's position in paths(), or -1. */
    public function index(string $value): int
    {
        $index = array_search($value, $this->paths(), true);
        return $index === false ? -1 : $index;
    }

    /** @return array<string, string> constant value => absolute path */
    private function entries(): array
    {
        $out = [];
        foreach ($this->groups as $names) {
            foreach ($names as [$value, $absolute]) {
                $out[$value] ??= $absolute;
            }
        }
        return $out;
    }
}
