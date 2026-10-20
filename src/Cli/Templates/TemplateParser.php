<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/** Reads .rk files (XML + Rageblade directives) into Element trees. Whitespace between elements is ignored. */
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
        $xml = Rageblade::toXml($xml, $file);
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
        $isText = $node->localName === 'Text' && $node->namespaceURI === null;
        $content = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if ($isText) {
                    throw new TemplateException($file, $child->getLineNo(), '<Text> holds only text and {{ expressions }}, not <' . $child->localName . '>');
                }
                $children[] = self::element($child, $file);
            } elseif ($child instanceof \DOMText && $isText) {
                $content .= $child->textContent;
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                throw new TemplateException($file, $node->getLineNo(), 'text content isn\'t supported, use text="…" (found "' . trim($child->textContent) . '")');
            }
        }
        if ($isText && trim($content) !== '') {
            if (isset($attributes['text'])) {
                throw new TemplateException($file, $node->getLineNo(), '<Text> has both text="…" and content; use one');
            }
            // Like HTML: runs of spaces and newlines become one space (also inside {{ }}, where it doesn't matter).
            $attributes['text'] = trim((string) preg_replace('/\s+/', ' ', $content));
        }
        return new Element($node->localName, $node->namespaceURI, $attributes, $children, $node->getLineNo(), $file);
    }
}
