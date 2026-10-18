<?php

declare(strict_types=1);

namespace RageKit\Tests\Templates;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Cli\Templates\TemplateParser;

final class TemplateParserTest extends TestCase
{
    public function testParsesTreeWithAttributesAndLines(): void
    {
        $root = TemplateParser::parse(<<<XML
            <VStack padded="false">
                <Label text="Count: {count}" />
                <Button text="+" onClick="increment" grow="true" />
            </VStack>
            XML, 'Counter.rk');

        self::assertSame('VStack', $root->tag);
        self::assertNull($root->namespace);
        self::assertSame(['padded' => 'false'], $root->attributes);
        self::assertCount(2, $root->children);
        self::assertSame('Button', $root->children[1]->tag);
        self::assertSame(['text' => '+', 'onClick' => 'increment', 'grow' => 'true'], $root->children[1]->attributes);
        self::assertSame(3, $root->children[1]->line);
        self::assertSame('Counter.rk', $root->children[1]->file);
    }

    public function testNamespacedTagCarriesKitName(): void
    {
        $root = TemplateParser::parse('<VStack xmlns:ui="shadcn"><ui:Card title="x" /></VStack>', 'A.rk');
        self::assertSame('Card', $root->children[0]->tag);
        self::assertSame('shadcn', $root->children[0]->namespace);
        self::assertSame([], $root->attributes);
    }

    public function testMalformedXmlReportsLine(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessageMatches('/^Broken\.rk:\d+: Opening and ending tag mismatch/');
        TemplateParser::parse("<VStack>\n  <Label text=\"x\">\n</VStack>", 'Broken.rk');
    }

    public function testTextContentIsRejected(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Bad.rk:1: text content isn\'t supported, use text="…" (found "hello")');
        TemplateParser::parse('<Label>hello</Label>', 'Bad.rk');
    }

    public function testEmptyTemplate(): void
    {
        foreach (['', "  \n"] as $xml) {
            try {
                TemplateParser::parse($xml, 'Empty.rk');
                self::fail('Expected TemplateException for empty template');
            } catch (TemplateException $e) {
                self::assertSame('Empty.rk: template is empty', $e->getMessage());
            }
        }
    }

    public function testMissingFile(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('/nope/X.rk: template not found');
        TemplateParser::parseFile('/nope/X.rk');
    }
}
