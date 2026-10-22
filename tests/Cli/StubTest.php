<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Stub;

final class StubTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ragekit-stub-' . getmypid() . '-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        rmdir($this->dir);
    }

    private function stub(string $contents): string
    {
        $path = "$this->dir/" . uniqid() . '.stub';
        file_put_contents($path, $contents);
        return $path;
    }

    public function testFillsInlinePlaceholders(): void
    {
        self::assertSame("Timer { interval: 250ms; running: root.p1.length > 0; }\n",
            Stub::render($this->stub("Timer { interval: {{ interval }}; running: {{ list }}.length > 0; }\n"), ['interval' => '250ms', 'list' => 'root.p1']));
    }

    public function testIndentsABlockLikeItsPlaceholder(): void
    {
        $path = $this->stub("Rectangle {\n    width: 10px;\n    {{ body }}\n}\n");
        self::assertSame("Rectangle {\n    width: 10px;\n    Text {\n\n        text: \"a\";\n    }\n}\n",
            Stub::render($path, ['body' => "Text {\n\n    text: \"a\";\n}\n"]));
    }

    public function testAnEmptyBlockRemovesItsLine(): void
    {
        self::assertSame("A {\n}\n", Stub::render($this->stub("A {\n    {{ lines }}\n}\n"), ['lines' => '']));
    }

    public function testCrlfStubsWorkLikeLf(): void
    {
        self::assertSame("A {\n    b;\n}\n", Stub::render($this->stub("A {\r\n    {{ lines }}\r\n}\r\n"), ['lines' => 'b;']));
    }

    public function testAMissingValueIsAnError(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('no value for {{ name }}');
        Stub::render($this->stub("{{ name }}\n"), []);
    }

    public function testAnUnusedValueIsAnError(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('has no placeholder for: extra');
        Stub::render($this->stub("x\n"), ['extra' => '1']);
    }

    public function testAnInlinePlaceholderTakesOneLine(): void
    {
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('its value must be one line');
        Stub::render($this->stub("a: {{ v }};\n"), ['v' => "1\n2"]);
    }
}
