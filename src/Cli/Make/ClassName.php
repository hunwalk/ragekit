<?php

declare(strict_types=1);

namespace RageKit\Cli\Make;

use RageKit\Cli\RageKitException;

/** A name given to make:, e.g. "Auth/Login" for a screen: sub-folder Auth, class LoginScreen. */
final class ClassName
{
    /** PHP's reserved words and reserved type names, which can't name a class. */
    private const RESERVED = ['abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int', 'interface',
        'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print',
        'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch',
        'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield'];

    /** @param list<string> $folders sub-folders under the kind's folder */
    private function __construct(public readonly array $folders, public readonly string $short)
    {
    }

    public static function parse(string $input, MakeKind $kind): self
    {
        $segments = explode('/', str_replace('\\', '/', trim($input)));
        foreach ($segments as $i => $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new RageKitException("'$input' is not a valid class name" . ($segment === '' ? '' : " ('$segment')") . ': use letters, digits and _, not starting with a digit, folders separated by /');
            }
            $segments[$i] = ucfirst($segment);
            if (in_array(strtolower($segment), self::RESERVED, true)) {
                throw new RageKitException("'{$segments[$i]}' can't be a class name: it's a reserved word in PHP");
            }
        }
        $short = array_pop($segments);
        if ($kind->suffix() !== '' && !str_ends_with($short, $kind->suffix())) {
            $short .= $kind->suffix();
        }
        if (in_array($short, ['Screen', 'Window', 'Component', 'Store', 'RootStore', 'Navigator'], true)) {
            throw new RageKitException("'$short' can't be a class name: it's one of RageKit's base classes (pick a more specific name)");
        }
        if ($short === 'Box') {
            throw new RageKitException("'Box' can't be a class name: TypePHP reserves it");
        }
        return new self($segments, $short);
    }

    /** The path under src/ (and templates/) without extension: Screens/Auth/LoginScreen. */
    public function relative(MakeKind $kind): string
    {
        return implode('/', [$kind->folder(), ...$this->folders, $this->short]);
    }

    public function namespace(string $base, MakeKind $kind): string
    {
        return implode('\\', [$base, $kind->folder(), ...$this->folders]);
    }
}
