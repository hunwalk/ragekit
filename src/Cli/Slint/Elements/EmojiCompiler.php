<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * <Emoji text="{{ $reaction }}" size="32" />: the emoji in the text as colour images in a row
 * (RkEmoji.images, drawn natively from the system's colour emoji font: Slint's software renderer
 * can't draw colour glyphs). Other characters in the text are left out. size: pixels per emoji
 * (default 20).
 */
final class EmojiCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['Emoji'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $context = $this->context;
        $context->checkAttributes($element, ['text', 'size', 'grow']);
        if ($element->children !== []) {
            throw TemplateException::at($element, '<Emoji> has no content; put the emoji in text="…"');
        }
        if (!isset($element->attributes['text'])) {
            throw TemplateException::at($element, '<Emoji> needs text="…", e.g. text="👍" or text="{{ $reaction }}"');
        }
        $size = trim($element->attributes['size'] ?? '20');
        if (preg_match('/^[1-9]\d{0,3}$/', $size) !== 1) {
            throw TemplateException::at($element, "<Emoji> size=\"$size\": a number of pixels, e.g. size=\"32\"");
        }
        $text = $context->values->value($element->attributes['text'], 'string', null, $scope, $element, 'text');
        $id = $context->nextId('rk-emoji');
        $image = Code::block('Image', ["source: $id;", "width: {$size}px;", "height: {$size}px;", 'image-rendering: smooth;'], '', "for $id in RkEmoji.images($text, {$size}px) : ");
        return Code::block('HorizontalLayout', [
            'alignment: start;',
            'spacing: ' . max(1, intdiv((int) $size, 10)) . 'px;',
            $context->stretch($element, $axis),
        ], $image);
    }
}
