<?php

declare(strict_types=1);

namespace RageKit\Cli\Modules;

use RageKit\Cli\Fetch\Dependency;
use RageKit\Cli\Project\ConfigMerger;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\RageKitException;
use Symfony\Component\Yaml\Yaml;

/**
 * A native module (modules/<name>/module.yml): its PHP API, its native library and the functions
 * that library exports. RageKit generates all glue between them (ModuleEmitter).
 */
final class Module
{
    public const TYPES = ['int', 'float', 'bool', 'string'];

    /** Names the generated C++/PHP uses itself, plus C++ keywords and PHP's $this. */
    private const RESERVED = [
        'fn', 'module', 'name', 'this', 'alignas', 'alignof', 'and', 'asm', 'auto', 'bool', 'break', 'case', 'catch',
        'char', 'class', 'const', 'constexpr', 'continue', 'default', 'delete', 'do', 'double', 'else', 'enum',
        'explicit', 'export', 'extern', 'false', 'float', 'for', 'friend', 'goto', 'if', 'inline', 'int', 'long',
        'mutable', 'namespace', 'new', 'noexcept', 'not', 'nullptr', 'operator', 'or', 'private', 'protected',
        'public', 'register', 'return', 'short', 'signed', 'sizeof', 'static', 'struct', 'switch', 'template',
        'throw', 'true', 'try', 'typedef', 'typename', 'union', 'unsigned', 'using', 'virtual', 'void',
        'volatile', 'while', 'xor',
    ];

    /**
     * @param list<string>                  $php      PHP API files/folders (absolute)
     * @param array<string, list<string>>   $library  sources, include-paths, link-paths (absolute), link-libs
     * @param array<string, ModuleFunction> $functions
     * @param list<Dependency>              $fetch    third-party code it needs (module.yml `fetch:`)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly array $php,
        public readonly array $library,
        public readonly array $functions,
        public readonly array $fetch = [],
    ) {
    }

    public static function load(string $file, string $rageKitRoot): self
    {
        $real = realpath($file) ?: throw new RageKitException("module.yml not found: $file");
        $dir = Paths::normalize(dirname($real));
        $raw = Yaml::parseFile($real);
        if (!is_array($raw)) {
            throw new RageKitException("$file: not a module.yml");
        }
        $raw = self::fixed($raw, ['${RAGEKIT}' => $rageKitRoot, '${MODULE}' => $dir]);
        $name = (string) ($raw['name'] ?? throw new RageKitException("$file: name is missing"));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new RageKitException("$file: name '$name' must be lowercase letters, digits and _ (it becomes C and PHP names)");
        }

        $library = $raw['library'] ?? [];
        $library = ConfigMerger::forPlatform($library, "$file: library");
        // Joined, not resolved: a module may name folders that a later build step or checkout creates.
        $paths = fn(array $list) => array_values(array_map(
            fn($p) => preg_match('~^([A-Za-z]:)?[\\\\/]~', (string) $p) === 1 ? Paths::normalize((string) $p) : $dir . '/' . Paths::normalize((string) $p),
            $list,
        ));
        $library = [
            'sources' => $paths($library['sources'] ?? []),
            'include-paths' => $paths($library['include-paths'] ?? []),
            'link-paths' => $paths($library['link-paths'] ?? []),
            'link-libs' => array_values(array_map('strval', $library['link-libs'] ?? [])),
        ];

        $functions = [];
        foreach ($raw['functions'] ?? [] as $fn => $spec) {
            $fn = (string) $fn;
            if (preg_match('/^[a-z][a-z0-9_]*$/', $fn) !== 1 || !str_starts_with($fn, "{$name}_")) {
                throw new RageKitException("$file: functions.$fn: function names must start with {$name}_ (lowercase letters, digits, _)");
            }
            $params = [];
            foreach ($spec['params'] ?? [] as $param => $type) {
                $param = (string) $param;
                if (preg_match('/^[a-z_][a-z0-9_]*$/i', $param) !== 1 || in_array(strtolower($param), self::RESERVED, true) || str_ends_with($param, '_len')) {
                    throw new RageKitException("$file: functions.$fn.params.$param: '$param' can't be a parameter name (letters, digits, _; not a C++ keyword or fn/module/name/this, not ending in _len)");
                }
                $params[$param] = self::type((string) $type, "$file: functions.$fn.params.$param");
            }
            $returns = isset($spec['returns']) ? self::type((string) $spec['returns'], "$file: functions.$fn.returns") : null;
            $functions[$fn] = new ModuleFunction($fn, $params, $returns);
        }
        return new self($name, $dir, $paths($raw['php'] ?? []), $library, $functions, Dependency::list($raw['fetch'] ?? [], $dir, $file));
    }

    private static function type(string $type, string $where): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new RageKitException("$where: unknown type '$type' (use " . implode(', ', self::TYPES) . ')');
        }
        return $type;
    }

    /** ${RAGEKIT} and ${MODULE} are fixed paths: environment variables must not override them. */
    private static function fixed(mixed $value, array $vars): mixed
    {
        if (is_array($value)) {
            return array_map(fn($v) => self::fixed($v, $vars), $value);
        }
        return is_string($value) ? strtr($value, $vars) : $value;
    }
}
