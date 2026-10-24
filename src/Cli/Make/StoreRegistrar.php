<?php

declare(strict_types=1);

namespace RageKit\Cli\Make;

use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\RageKitException;

/**
 * make:store's one edit of an existing file: adds the new store to the app's RootStore subclass as
 * a typed property and an assignment in its constructor, leaving every other byte as it was.
 */
final class StoreRegistrar
{
    /**
     * The file and class name of the class under $srcDir that extends RootStore (null: none).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function findRoot(string $srcDir): ?array
    {
        foreach (SourceFiles::php([$srcDir]) as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $namespace = '';
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_NAMESPACE) {
                    $namespace = self::nextName($tokens, $i);
                }
                if ($token[0] === T_EXTENDS) {
                    $parent = self::nextName($tokens, $i);
                    $class = self::previousName($tokens, $i);
                    if ($class !== '' && ($parent === 'RootStore' || str_ends_with($parent, '\\RootStore'))) {
                        return [$file, ltrim("$namespace\\$class", '\\')];
                    }
                }
            }
        }
        return null;
    }

    /**
     * $source with `public $type $$property;` after the last property of class $rootClass (or first
     * in it) and `$this->$property = new $type();` at the end of its constructor. Anything it can't
     * edit safely (no constructor, a one-line constructor, a result that wouldn't parse) is refused.
     *
     * @param string $rootClass the root store's short class name (the class to edit)
     * @throws RageKitException naming the two lines to add by hand
     */
    public static function register(string $source, string $file, string $type, string $property, string $store, string $rootClass): string
    {
        $fail = fn(string $why) => new RageKitException("couldn't add $store to $file ($why); add these yourself:\n"
            . "  public $type \$$property;            (a property of $rootClass)\n"
            . "  \$this->$property = new $type();  (in its constructor)");
        $tokens = token_get_all($source);
        $offset = 0;
        $depth = 0;
        $classDepth = null;    // depth inside $rootClass's body once entered
        $classOpen = null;     // offset just after $rootClass's {
        $wantClass = false;    // saw `class $rootClass`, waiting for its {
        $lastProperty = null;  // offset just after the last ; of a property of $rootClass
        $ctorDepth = null;
        $ctorClose = null;
        $inCtor = false;
        $sawVariable = false;
        $previous = null;      // last non-whitespace token id
        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : $token;
            $inClass = $classDepth !== null && $depth === $classDepth && $ctorClose === null;
            if ($id === T_CLASS && $classOpen === null && $previous !== T_DOUBLE_COLON && $previous !== T_NEW && self::nextName($tokens, $i) === $rootClass) {
                $wantClass = true;
            } elseif ($inClass && $id === T_STRING && strtolower($text) === '__construct') {
                $inCtor = true;
            } elseif ($inClass && $id === T_VARIABLE && !$inCtor) {
                $sawVariable = true;
                if ($text === "\$$property") {
                    throw $fail("it already has \$$property");
                }
            } elseif ($inClass && $id === ';' && $sawVariable) {
                $lastProperty = $offset + 1;
                $sawVariable = false;
            } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($wantClass) {
                    $classOpen = $offset + strlen($text);
                    $classDepth = $depth;
                    $wantClass = false;
                } elseif ($inCtor && $ctorDepth === null) {
                    $ctorDepth = $depth;
                }
            } elseif ($id === '}') {
                if ($ctorDepth !== null && $depth === $ctorDepth && $ctorClose === null) {
                    $ctorClose = $offset;
                }
                $depth--;
            }
            if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                $previous = $id;
            }
            $offset += strlen($text);
        }
        if ($classOpen === null) {
            throw $fail("class $rootClass not found");
        }
        if ($ctorClose === null) {
            throw $fail('it has no constructor');
        }
        $lineStart = (int) strrpos(substr($source, 0, $ctorClose), "\n");
        $indent = substr($source, $lineStart + 1, $ctorClose - $lineStart - 1);
        if (trim($indent) !== '') {
            throw $fail("its constructor's closing } isn't on a line of its own");
        }
        // The assignment goes on its own line before the constructor's closing brace.
        $result = substr($source, 0, $lineStart + 1) . "$indent    \$this->$property = new $type();\n" . substr($source, $lineStart + 1);
        // The property goes after the last one, or first in the class.
        $at = $lastProperty ?? $classOpen;
        $eol = strpos($result, "\n", $at);
        $at = $eol === false ? $at : $eol + 1;
        $result = substr($result, 0, $at) . "    public $type \$$property;\n" . ($lastProperty === null ? "\n" : '') . substr($result, $at);
        try {
            token_get_all($result, TOKEN_PARSE);
        } catch (\ParseError) {
            throw $fail('the edit would not parse');
        }
        return $result;
    }

    /** @param list<mixed> $tokens */
    private static function nextName(array $tokens, int $i): string
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return ltrim($tokens[$j][1], '\\');
            }
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                return '';
            }
        }
        return '';
    }

    /** @param list<mixed> $tokens */
    private static function previousName(array $tokens, int $i): string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                return '';
            }
        }
        return '';
    }
}
