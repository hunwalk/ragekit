<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Build\StatusLine;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusLineTest extends TestCase
{
    public function testTextReplacesThePreviousTextInPlace(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $status = new StatusLine($output);
        $status->show('compiling a.cc');
        $status->show('compiling a.cc', 2);
        $status->clear();

        self::assertSame(
            "compiling a.cc"
            . "\e[14D\e[K" . "compiling a.cc · 2 s"
            . "\e[20D\e[K",
            $output->fetch(),
        );
    }

    public function testClearWithoutTextWritesNothing(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        (new StatusLine($output))->clear();
        self::assertSame('', $output->fetch());
    }

    public function testSilentWhenNotATerminalOrVerbose(): void
    {
        foreach ([new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false), new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE, true)] as $output) {
            $status = new StatusLine($output);
            self::assertFalse($status->enabled());
            $status->show('compiling a.cc');
            $status->clear();
            self::assertSame('', $output->fetch());
        }
    }

    public function testLongTextIsCutToTheRestOfTheTerminalLine(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $status = new StatusLine($output, width: 30);
        $status->startsAt(10);
        $status->show('g++: aaaa.cc, bbbb.cc, cccc.cc → lib.so', 12);
        $status->clear();

        self::assertSame('g++: aaaa.c…' . ' · 12 s' . "\e[19D\e[K", $output->fetch(), 'the seconds are kept, the text is cut');
    }
}
