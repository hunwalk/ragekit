<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * <ImageViewer source="{{ $url }}" />: an image to look at closely. It starts fitted into the room it
 * gets (never enlarged); the wheel zooms in and out around the pointer (up to 8×), dragging pans, and
 * a double-click switches between fitted and 100 %. All in Slint: no PHP runs while zooming.
 */
final class ImageViewerCompiler implements ElementCompiler
{
    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['ImageViewer'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $context = $this->context;
        $context->checkAttributes($element, ['source', 'fallback', 'grow', 'when', 'each'], styled: true);
        if ($element->children !== []) {
            throw TemplateException::at($element, '<ImageViewer> has no content');
        }
        $raw = $element->attributes['source'] ?? throw TemplateException::at($element, '<ImageViewer> needs source="…": a path, URL or {{ bound image }}');
        $source = $context->values->value($raw, 'image', null, $scope, $element, 'source');
        if (isset($element->attributes['fallback']) && !str_starts_with($source, '@image-url(')) {
            $fallback = $context->values->staticImage($element->attributes['fallback'], $element, 'fallback');
            $source = "$source.width > 0 ? $source : $fallback";
        }
        $style = $context->styleOf($element, $scope, 'stack');
        $lines = CompileContext::styleLines($style, $style->box);
        // Without a size of its own: as big as the image when there's room (a plain Rectangle would
        // ask for none).
        foreach (['width' => 'rk-w', 'height' => 'rk-h'] as $side => $natural) {
            if (preg_match("/^(min-|max-)?$side:/m", implode("\n", $lines)) !== 1) {
                $lines[] = "min-$side: 80px;";
                $lines[] = "preferred-$side: self.$natural;";
            }
        }
        $lines[] = isset($element->attributes['grow']) || $style->flex !== null ? $context->stretch($element, $axis)
            : ($axis === 'h' ? 'horizontal' : 'vertical') . '-stretch: 1;';
        $code = Code::stub('image-viewer', [
            'id' => $context->nextId('rk-viewer'),
            'lines' => implode("\n", $lines),
            'source' => $source,
        ]);
        return $context->withMargin($code, $style, $element, $axis);
    }
}
