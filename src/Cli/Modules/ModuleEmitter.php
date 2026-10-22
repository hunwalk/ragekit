<?php

declare(strict_types=1);

namespace RageKit\Cli\Modules;

use RageKit\Cli\Stub;

/**
 * Everything RageKit generates from a module.yml:
 *  - header():       rk_module_<name>.h, the C ABI the module's library implements;
 *  - callEntry():    rk_module_<name>_call.cc, compiled into the library: call any function by
 *                    name with text arguments (the dev shell's single entry point);
 *  - stub(), host(): the PHP functions and their C++17 PHPX host for release builds, which find
 *                    the library at runtime through libragekit_slint;
 *  - devFunctions(): the same PHP functions for `ragekit dev`, calling over the dev protocol.
 */
final class ModuleEmitter
{
    private const C = ['int' => 'int64_t', 'float' => 'double', 'bool' => 'int', 'string' => 'const char *'];
    private const PHPX = ['int' => 'Int', 'float' => 'Float', 'bool' => 'Bool', 'string' => 'String'];

    public static function header(Module $m): string
    {
        $functions = [];
        foreach ($m->functions as $fn) {
            $functions[] = 'RKM_API ' . self::cReturn($fn->returns) . "rkm_{$fn->name}(" . self::cParams($fn) . ');';
        }
        return self::render('header.h', ['name' => $m->name, 'functions' => implode("\n", $functions)]);
    }

    public static function callEntry(Module $m): string
    {
        $cases = [];
        foreach ($m->functions as $fn) {
            $n = count($fn->params);
            $prepare = [];
            $args = [];
            $i = 0;
            foreach ($fn->params as $type) {
                $prepare[] = "const std::string a$i(args[$i], lens[$i]);";
                $args[] = match ($type) {
                    'int' => "std::strtoll(a$i.c_str(), nullptr, 10)",
                    'float' => "std::strtod(a$i.c_str(), nullptr)",
                    'bool' => "a$i == \"1\" ? 1 : 0",
                    default => "a$i.data(), a$i.size()",
                };
                $i++;
            }
            $call = "rkm_{$fn->name}(" . implode(', ', $args) . ')';
            $result = match ($fn->returns) {
                null => "$call;\nout.clear();",
                'int' => "out = std::to_string($call);",
                'float' => "out = number($call);",
                'bool' => "out = $call ? \"1\" : \"0\";",
                // A NULL string result (the module broke its contract) reads as "".
                default => "const char *result = $call;\nout = result != nullptr ? result : \"\";",
            };
            $cases[] = rtrim(self::render('call-case.cc', [
                'function' => $fn->name,
                'count' => (string) $n,
                'arguments' => $n === 1 ? 'argument' : 'arguments',
                'prepare' => implode("\n", $prepare),
                'result' => $result,
            ]), "\n");
        }
        return self::render('call.cc', ['name' => $m->name, 'cases' => implode("\n", $cases)]);
    }

    public static function stub(Module $m): string
    {
        $functions = [];
        foreach ($m->functions as $fn) {
            $functions[] = 'function ' . self::phpSignature($fn) . ' {}';
        }
        return self::render('stub.php', ['name' => $m->name, 'functions' => implode("\n\n", $functions)]);
    }

    public static function host(Module $m): string
    {
        $functions = '';
        foreach ($m->functions as $fn) {
            $params = [];
            $args = [];
            foreach ($fn->params as $name => $type) {
                $params[] = self::PHPX[$type] . " $name";
                $args[] = match ($type) {
                    'string' => "$name.data(), $name.length()",
                    'bool' => "$name ? 1 : 0",
                    default => $name,
                };
            }
            $call = 'fn(' . implode(', ', $args) . ')';
            $functions .= "\n" . self::render('host-function.cc', [
                'returns' => $fn->returns === null ? 'void' : self::PHPX[$fn->returns],
                'function' => $fn->name,
                'params' => implode(', ', $params),
                'default' => match ($fn->returns) {
                    null => 'return;',
                    'int' => 'return 0;',
                    'float' => 'return 0.0;',
                    'bool' => 'return false;',
                    default => 'return String("");',
                },
                'body' => match ($fn->returns) {
                    null => "$call;",
                    'bool' => "return $call != 0;",
                    'string' => "const char *result = $call;\nreturn String(result != nullptr ? result : \"\");",
                    default => "return $call;",
                },
            ]);
        }
        return self::render('host.cc', [
            'name' => $m->name,
            'symbols' => implode('\\n', array_map(fn($f) => "rkm_$f", array_keys($m->functions))),
            'functions' => $functions,
        ]);
    }

    public static function devFunctions(Module $m): string
    {
        $functions = '';
        foreach ($m->functions as $fn) {
            $args = [];
            foreach ($fn->params as $name => $type) {
                $args[] = match ($type) {
                    'int' => "(string) \$$name",
                    'float' => "var_export(\$$name, true)",
                    'bool' => "\$$name ? '1' : '0'",
                    default => "\$$name",
                };
            }
            $call = "\\RageKit\\Slint\\Dev\\Session::call('{$fn->name}', [" . implode(', ', $args) . '])';
            $functions .= "\n" . self::render('dev-function.php', [
                'signature' => self::phpSignature($fn),
                'body' => match ($fn->returns) {
                    null => "$call;",
                    'int' => "return (int) $call;",
                    'float' => "return (float) $call;",
                    'bool' => "return $call === '1';",
                    default => "return $call;",
                },
            ]);
        }
        return self::render('dev.php', ['name' => $m->name, 'functions' => $functions]);
    }

    private static function phpSignature(ModuleFunction $fn): string
    {
        $params = [];
        foreach ($fn->params as $name => $type) {
            $params[] = "$type \$$name";
        }
        return "{$fn->name}(" . implode(', ', $params) . '): ' . ($fn->returns ?? 'void');
    }

    private static function cReturn(?string $type): string
    {
        return $type === null ? 'void ' : (self::C[$type] === 'const char *' ? 'const char *' : self::C[$type] . ' ');
    }

    private static function cParams(ModuleFunction $fn): string
    {
        $params = [];
        foreach ($fn->params as $name => $type) {
            $params[] = $type === 'string' ? "const char *$name, size_t {$name}_len" : self::C[$type] . " $name";
        }
        return $params === [] ? 'void' : implode(', ', $params);
    }

    /** @param array<string, string> $values */
    private static function render(string $name, array $values): string
    {
        return Stub::render(__DIR__ . "/stubs/$name.stub", $values);
    }
}
