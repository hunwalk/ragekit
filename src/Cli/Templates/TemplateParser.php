<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/** Reads .rk files (XML) into Element trees. Whitespace between elements is ignored. */
final class TemplateParser
{
    public static function parseFile(string $file): Element
    {
        if (!is_file($file)) {
            throw new TemplateException($file, 0, 'template not found');
        }
        $xml = file_get_contents($file);
        if ($xml === false) {
            throw new TemplateException($file, 0, 'cannot read template');
        }
        return self::parse($xml, $file);
    }

    public static function parse(string $xml, string $file): Element
    {
        if (trim($xml) === '') {
            throw new TemplateException($file, 0, 'template is empty');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->documentElement === null) {
            $error = $errors[0] ?? null;
            throw new TemplateException($file, $error !== null ? $error->line : 0, trim($error !== null ? $error->message : 'not a valid XML document'));
        }
        return self::element($document->documentElement, $file);
    }

    private static function element(\DOMElement $node, string $file): Element
    {
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->value;
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = self::element($child, $file);
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                throw new TemplateException($file, $node->getLineNo(), 'text content isn\'t supported, use text="…" (found "' . trim($child->textContent) . '")');
            }
        }
        return new Element($node->localName, $node->namespaceURI, $attributes, $children, $node->getLineNo(), $file);
    }
}
