<?php

declare(strict_types=1);

namespace RageKit\Tests\Rageblade;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Rageblade\Directives;
use RageKit\Cli\Templates\TemplateException;

final class DirectivesTest extends TestCase
{
    private static function xml(string $source): string
    {
        return Directives::toXml($source, 'T.rk');
    }

    public function testForeachBecomesElement(): void
    {
        $out = self::xml("<VStack>\n@foreach (\$items as \$item)\n<Label text=\"{{ \$item }}\" />\n@endforeach\n</VStack>");
        self::assertStringContainsString('<rk-foreach source="$items" as="item">', $out);
        self::assertStringContainsString('</rk-foreach>', $out);
        self::assertSame(4, substr_count($out, "\n"), 'line numbers are kept');
    }

    public function testForeachWithIndex(): void
    {
        $out = self::xml("<VStack>\n  @foreach (\$items as \$i => \$item)\n<Label />\n  @endforeach\n</VStack>");
        self::assertStringContainsString('<rk-foreach source="$items" as="item" index="i">', $out);
    }

    public function testIfElseifElse(): void
    {
        $out = self::xml("<VStack>\n@if (\$a)\n<Label />\n@elseif (\$b)\n<Label />\n@else\n<Label />\n@endif\n</VStack>");
        self::assertStringContainsString('<rk-if><rk-branch test="$a">', $out);
        self::assertStringContainsString('</rk-branch><rk-branch test="$b">', $out);
        self::assertStringContainsString('</rk-branch><rk-branch>', $out);
        self::assertStringContainsString('</rk-branch></rk-if>', $out);
        self::assertTrue((new \DOMDocument())->loadXML($out));
    }

    public function testStyleDirective(): void
    {
        $out = self::xml("<View @style(['row', 'selected' => \$item === \$sel])>\n</View>");
        self::assertStringContainsString('rk-style="[\'row\', \'selected\' =&gt; $item === $sel]"', $out);
        self::assertTrue((new \DOMDocument())->loadXML($out));
    }

    public function testExpressionsAreEscaped(): void
    {
        $out = self::xml("<VStack>\n<Label text=\"{{ \$a < \$b && \$c }}\" />\n@if (count(\$x) < 3)\n<Label />\n@endif\n</VStack>");
        self::assertStringContainsString('{{ $a &lt; $b &amp;&amp; $c }}', $out);
        self::assertStringContainsString('test="count($x) &lt; 3"', $out);
        self::assertTrue((new \DOMDocument())->loadXML($out));
    }

    public function testExpressionInContentIsEscaped(): void
    {
        $out = self::xml('<Text>Less: {{ $a < $b ? "y" : "n" }} & more</Text>');
        self::assertStringContainsString('{{ $a &lt; $b ? &quot;y&quot; : &quot;n&quot; }}', $out);
        self::assertTrue((new \DOMDocument())->loadXML(str_replace(' & ', ' &amp; ', $out)));
    }

    public function testBareLessThanInContentIsEscaped(): void
    {
        $out = self::xml('<VStack><Text>Score < 10, 1<2</Text><!-- a < b --></VStack>');
        self::assertStringContainsString('<Text>Score &lt; 10, 1&lt;2</Text>', $out);
        self::assertStringContainsString('<!-- a < b -->', $out);
        self::assertTrue((new \DOMDocument())->loadXML($out));
    }

    public function testStringsInsideExpressionsMayHoldQuotes(): void
    {
        $out = self::xml("<Label text=\"{{ \$a ? \"yes\" : 'no' }}\" />");
        self::assertStringContainsString('{{ $a ? &quot;yes&quot; : \'no\' }}', $out);
        self::assertTrue((new \DOMDocument())->loadXML($out));
    }

    public function testNestedParensAndStrings(): void
    {
        $out = self::xml("<VStack>\n@if (f(g(1), ')') === 'x')\n<Label />\n@endif\n</VStack>");
        self::assertStringContainsString('test="f(g(1), \')\') === \'x\'"', $out);
    }

    public function testUnclosedBlock(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('T.rk:2: @foreach is never closed with @endforeach');
        self::xml("<VStack>\n@foreach (\$a as \$b)\n<Label />\n</VStack>");
    }

    public function testStrayEnd(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('T.rk:2: @endif without @if');
        self::xml("<VStack>\n@endif\n</VStack>");
    }

    public function testMismatchedEnd(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('T.rk:3: @endif closes @foreach (line 2); expected @endforeach');
        self::xml("<VStack>\n@foreach (\$a as \$b)\n@endif\n</VStack>");
    }

    public function testOldTemplatesUnchanged(): void
    {
        $old = "<VStack>\n    <Label text=\"Count: {count}\" size=\"title\" />\n    <Button text=\"+\" onClick=\"increment\" />\n"
            . "    <Label each=\"entry in history\" when=\"showHistory\" text=\"{entry}\" />\n    <Label text=\"a &amp; b\" />\n</VStack>\n";
        self::assertSame($old, self::xml($old));
    }

    public function testAtSignsElsewhereAreLeftAlone(): void
    {
        $src = '<Label text="mail me@example.com" />';
        self::assertSame($src, self::xml($src));
    }
}
