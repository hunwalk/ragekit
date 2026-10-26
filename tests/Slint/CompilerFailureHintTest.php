<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\SlintViewGenerator;

/** When slint-compiler fails in a known way, the build says what to do and keeps its output short. */
final class CompilerFailureHintTest extends TestCase
{
    private static function call(string $method, string $output): string
    {
        $m = new \ReflectionMethod(SlintViewGenerator::class, $method);
        return (string) $m->invoke(null, $output);
    }

    public function testBindingLoop(): void
    {
        $one = "error: The binding for the property 'width' is part of a binding loop (width -> height)\n  --> app.slint:3:5\n";
        $output = str_repeat($one, 10);
        self::assertStringContainsString('Use <Label>', self::call('hint', $output));
        $short = self::call('shortened', $output);
        self::assertSame(3, substr_count($short, 'binding loop (width'));
        self::assertStringEndsWith("… and 7 more binding-loop errors\n", $short);
        self::assertSame($one, self::call('shortened', $one), 'a few errors stay as they are');
    }

    public function testStackOverflow(): void
    {
        self::assertStringContainsString("flexDirection: 'row'", self::call('hint', "thread 'main' has overflowed its stack\nfatal runtime error: stack overflow"));
    }

    public function testOtherErrorsGetNoHint(): void
    {
        self::assertSame('', self::call('hint', "error: Unknown property foo\n"));
        self::assertSame('', self::call('hint', "warning: The binding for the property 'x' is part of a binding loop\n"), 'warnings alone aren\'t why it failed');
    }
}
