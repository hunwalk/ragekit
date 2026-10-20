<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/**
 * Rageblade: the Blade-flavoured parts of .rk templates, turned into plain XML before parsing.
 * Line numbers are kept, so errors still point at the author's lines.
 *
 *   @foreach (EXPR as $item) … @endforeach        → <rk-foreach source="EXPR" as="item"> … </rk-foreach>
 *   @foreach (EXPR as $i => $item)                 → … as="item" index="i"
 *   @if (A) … @elseif (B) … @else … @endif         → <rk-if><rk-branch test="A"> … </rk-branch><rk-branch test="B"> … </rk-branch><rk-branch> … </rk-branch></rk-if>
 *   <View @style([...])>                           → <View rk-style="[...]">
 *
 * Inside {{ … }} (in attributes and in <Text> content) and directive arguments the author writes
 * plain PHP: & < > " are escaped here. A bare & in content (Rock & Roll) is escaped too.
 * Control directives count only as the first thing on a line; an @ anywhere else is left alone.
 */
final class Rageblade
{
    private const OPENERS = ['foreach' => 'endforeach', 'if' => 'endif'];

    public static function toXml(string $source, string $file): string
    {
        return (new self($source, $file))->run();
    }

    private string $out = '';
    private int $pos = 0;
    private int $line = 1;
    /** @var list<array{0: string, 1: int}> open blocks: directive, line */
    private array $open = [];

    private function __construct(private readonly string $src, private readonly string $file)
    {
    }

    private function run(): string
    {
        $length = strlen($this->src);
        $inTag = false;
        $quote = null;
        $lineStart = true;
        while ($this->pos < $length) {
            $c = $this->src[$this->pos];
            if ($lineStart && !$inTag && ($c === ' ' || $c === "\t")) {
                $this->out .= $c;
                $this->pos++;
                continue;
            }
            if ($lineStart && !$inTag && $c === '@' && $this->control()) {
                $lineStart = false;
                continue;
            }
            $lineStart = false;
            if ($quote !== null) {
                if (substr($this->src, $this->pos, 2) === '{{') {
                    $this->expression();
                    continue;
                }
                if ($c === $quote) {
                    $quote = null;
                } elseif ($c === '<' || ($c === '&' && preg_match('/\G&(#\d+|#x[0-9a-fA-F]+|[A-Za-z]+);/', $this->src, $m, 0, $this->pos) !== 1)) {
                    // Handler arguments are expressions too: onClick="pick($a < 3 && $ok)". A raw
                    // < or a & that starts no entity is never valid XML, so escape it here.
                    $this->out .= $c === '<' ? '&lt;' : '&amp;';
                    $this->pos++;
                    continue;
                }
            } elseif ($inTag) {
                if ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '>') {
                    $inTag = false;
                } elseif ($c === '@' && substr($this->src, $this->pos, 7) === '@style(') {
                    $this->pos += 6;
                    $this->out .= 'rk-style="' . self::escape($this->arguments()) . '"';
                    continue;
                }
            } elseif (substr($this->src, $this->pos, 2) === '{{') {
                // <Text>Hello {{ $name }}</Text>: content expressions are escaped like attribute ones.
                $this->expression();
                continue;
            } elseif ($c === '&' && preg_match('/\G&(#\d+|#x[0-9a-fA-F]+|[A-Za-z]+);/', $this->src, $m, 0, $this->pos) !== 1) {
                $this->out .= '&amp;';
                $this->pos++;
                continue;
            } elseif (substr($this->src, $this->pos, 4) === '<!--') {
                // A comment is copied as it is: directives inside it don't count.
                $end = strpos($this->src, '-->', $this->pos);
                $end = $end === false ? $length : $end + 3;
                $comment = substr($this->src, $this->pos, $end - $this->pos);
                $this->out .= $comment;
                $this->line += substr_count($comment, "\n");
                $this->pos = $end;
                $lineStart = false;
                continue;
            } elseif ($c === '<' && preg_match('/[A-Za-z]/', $this->src[$this->pos + 1] ?? '') === 1) {
                $inTag = true;
            } elseif ($c === '<' && preg_match('/[\/!?]/', $this->src[$this->pos + 1] ?? '') !== 1) {
                // <Text>Score < 10</Text>: a < that starts no tag is text.
                $this->out .= '&lt;';
                $this->pos++;
                continue;
            }
            if ($c === "\n") {
                $this->line++;
                $lineStart = true;
            }
            $this->out .= $c;
            $this->pos++;
        }
        if ($this->open !== []) {
            [$directive, $line] = end($this->open);
            throw new TemplateException($this->file, $line, "@$directive is never closed with @" . self::OPENERS[$directive]);
        }
        return $this->out;
    }

    /** A control directive at the start of a line; false (nothing consumed) if it isn't one. */
    private function control(): bool
    {
        if (preg_match('/\G@(foreach|endforeach|if|elseif|else|endif)\b/', $this->src, $m, 0, $this->pos) !== 1) {
            return false;
        }
        $directive = $m[1];
        $line = $this->line;
        $this->pos += strlen($m[0]);
        if (in_array($directive, ['foreach', 'if', 'elseif'], true)) {
            while (($this->src[$this->pos] ?? '') === ' ') {
                $this->pos++;
            }
            if (($this->src[$this->pos] ?? '') !== '(') {
                throw new TemplateException($this->file, $line, "@$directive needs its condition in parentheses, e.g. @$directive (\$value)");
            }
            $argument = trim($this->arguments());
        }
        switch ($directive) {
            case 'foreach':
                if (preg_match('/^(.+?)\s+as\s+\$(\w+)(?:\s*=>\s*\$(\w+))?$/s', $argument, $parts) !== 1) {
                    throw new TemplateException($this->file, $line, '@foreach must look like @foreach ($items as $item) or @foreach ($items as $i => $item)');
                }
                $index = isset($parts[3]) ? $parts[2] : null;
                $item = $parts[3] ?? $parts[2];
                $this->out .= '<rk-foreach source="' . self::escape(trim($parts[1])) . '" as="' . $item . '"'
                    . ($index === null ? '' : ' index="' . $index . '"') . '>';
                $this->open[] = ['foreach', $line];
                return true;
            case 'if':
                $this->out .= '<rk-if><rk-branch test="' . self::escape($argument) . '">';
                $this->open[] = ['if', $line];
                return true;
            case 'elseif':
                $this->inIf($directive, $line);
                $this->out .= '</rk-branch><rk-branch test="' . self::escape($argument) . '">';
                return true;
            case 'else':
                $this->inIf($directive, $line);
                $this->out .= '</rk-branch><rk-branch>';
                return true;
            default:
                $opener = $directive === 'endif' ? 'if' : 'foreach';
                $top = end($this->open);
                if ($top === false) {
                    throw new TemplateException($this->file, $line, "@$directive without @$opener");
                }
                if ($top[0] !== $opener) {
                    throw new TemplateException($this->file, $line, "@$directive closes @{$top[0]} (line {$top[1]}); expected @" . self::OPENERS[$top[0]]);
                }
                array_pop($this->open);
                $this->out .= $directive === 'endif' ? '</rk-branch></rk-if>' : '</rk-foreach>';
                return true;
        }
    }

    private function inIf(string $directive, int $line): void
    {
        $top = end($this->open);
        if ($top === false || $top[0] !== 'if') {
            throw new TemplateException($this->file, $line, "@$directive without @if");
        }
    }

    /** At "(": returns what's between it and its matching ")" (quotes and nesting respected), after it. */
    private function arguments(): string
    {
        $start = $this->pos + 1;
        $depth = 0;
        $quote = null;
        $length = strlen($this->src);
        for ($i = $this->pos; $i < $length; $i++) {
            $c = $this->src[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "\n") {
                break;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    $this->pos = $i + 1;
                    return substr($this->src, $start, $i - $start);
                }
            }
        }
        throw new TemplateException($this->file, $this->line, 'unclosed parenthesis (directives and their arguments must fit on one line)');
    }

    /** At "{{": copies the expression up to "}}" escaped (quotes inside it don't end the attribute). */
    private function expression(): void
    {
        $length = strlen($this->src);
        $quote = null;
        for ($i = $this->pos + 2; $i < $length - 1; $i++) {
            $c = $this->src[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '}' && $this->src[$i + 1] === '}') {
                $this->out .= '{{' . self::escape(substr($this->src, $this->pos + 2, $i - $this->pos - 2)) . '}}';
                $this->line += substr_count($this->src, "\n", $this->pos, $i - $this->pos);
                $this->pos = $i + 2;
                return;
            }
        }
        throw new TemplateException($this->file, $this->line, '{{ is never closed with }}');
    }

    private static function escape(string $php): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $php);
    }
}
