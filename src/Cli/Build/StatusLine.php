<?php

declare(strict_types=1);

namespace RageKit\Cli\Build;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Live text after a step's progress line ("compiling views_glue.cc · 9 s"), rewritten in place.
 * Only in a terminal without -v: piped output and verbose builds (which stream tool output)
 * stay plain.
 */
final class StatusLine
{
    private int $shown = 0;

    private int $column = 0;

    /** @param int|null $width terminal columns (default: the current terminal's) */
    public function __construct(private readonly OutputInterface $output, private readonly ?int $width = null)
    {
    }

    /** The column the text starts at, so it can be cut to fit the rest of the line (text that
     *  wrapped couldn't be erased in place). */
    public function startsAt(int $column): void
    {
        $this->column = $column;
    }

    public function enabled(): bool
    {
        return $this->output->isDecorated() && !$this->output->isVerbose();
    }

    /** Shows $text (what a step is running) and, once a second has passed, " · N s" after it. */
    public function show(string $text, int $seconds = 0): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->clear();
        $suffix = $seconds > 0 ? " · $seconds s" : '';
        preg_match_all('/./us', $text, $chars);
        $room = ($this->width ?? (new Terminal())->getWidth()) - $this->column - 1 - self::length($suffix);
        if ($room < 2) {
            return;
        }
        if (count($chars[0]) > $room) {
            $chars[0] = [...array_slice($chars[0], 0, $room - 1), '…'];
        }
        $line = implode('', $chars[0]) . $suffix;
        $this->output->write($line, false, OutputInterface::OUTPUT_RAW);
        $this->shown = self::length($line);
    }

    private static function length(string $text): int
    {
        return (int) preg_match_all('/./us', $text);
    }

    /** Removes the text, leaving the cursor where it started. */
    public function clear(): void
    {
        if ($this->shown > 0) {
            $this->output->write("\e[{$this->shown}D\e[K", false, OutputInterface::OUTPUT_RAW);
            $this->shown = 0;
        }
    }
}
