<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * React Native style properties RageKit understands, and what each becomes in Slint. Scopes say
 * where a property applies: box (any element's outer box), view (a <View>'s rectangle: background,
 * border, corners, shadow), layout (a <View>'s flex container, partly also VStack/HStack), text
 * (a <Label>), meta (handled by the compiler: margin, flex, position, transition).
 */
final class StyleProperties
{
    private const ALIGN = ['flex-start' => 'start', 'flex-end' => 'end', 'center' => 'center', 'stretch' => 'stretch'];

    /** @var array<string, array{scope: string, type: string, slint?: string|list<string>, enum?: array<string, string>}> */
    public const ALL = [
        'width' => ['scope' => 'box', 'type' => 'size', 'slint' => 'width'],
        'height' => ['scope' => 'box', 'type' => 'size', 'slint' => 'height'],
        'minWidth' => ['scope' => 'box', 'type' => 'size', 'slint' => 'min-width'],
        'minHeight' => ['scope' => 'box', 'type' => 'size', 'slint' => 'min-height'],
        'maxWidth' => ['scope' => 'box', 'type' => 'size', 'slint' => 'max-width'],
        'maxHeight' => ['scope' => 'box', 'type' => 'size', 'slint' => 'max-height'],
        'opacity' => ['scope' => 'box', 'type' => 'number', 'slint' => 'opacity'],
        'alignSelf' => ['scope' => 'box', 'type' => 'enum', 'slint' => 'cross-axis-self-alignment', 'enum' => ['auto' => 'auto'] + self::ALIGN],
        'margin' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['top', 'right', 'bottom', 'left']],
        'marginHorizontal' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['right', 'left']],
        'marginVertical' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['top', 'bottom']],
        'marginTop' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['top']],
        'marginRight' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['right']],
        'marginBottom' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['bottom']],
        'marginLeft' => ['scope' => 'meta', 'type' => 'length', 'slint' => ['left']],
        'flex' => ['scope' => 'meta', 'type' => 'number'],
        'flexGrow' => ['scope' => 'meta', 'type' => 'number'],
        'position' => ['scope' => 'meta', 'type' => 'enum', 'enum' => ['relative' => 'relative', 'absolute' => 'absolute']],
        'top' => ['scope' => 'meta', 'type' => 'length'],
        'right' => ['scope' => 'meta', 'type' => 'length'],
        'bottom' => ['scope' => 'meta', 'type' => 'length'],
        'left' => ['scope' => 'meta', 'type' => 'length'],
        'transition' => ['scope' => 'meta', 'type' => 'ms'],
        'backgroundColor' => ['scope' => 'view', 'type' => 'color', 'slint' => 'background'],
        'borderRadius' => ['scope' => 'view', 'type' => 'length', 'slint' => 'border-radius'],
        'borderWidth' => ['scope' => 'view', 'type' => 'length', 'slint' => 'border-width'],
        'borderColor' => ['scope' => 'view', 'type' => 'color', 'slint' => 'border-color'],
        'overflow' => ['scope' => 'view', 'type' => 'enum', 'slint' => 'clip', 'enum' => ['visible' => 'false', 'hidden' => 'true']],
        'shadowColor' => ['scope' => 'view', 'type' => 'color', 'slint' => 'drop-shadow-color'],
        'shadowRadius' => ['scope' => 'view', 'type' => 'length', 'slint' => 'drop-shadow-blur'],
        'shadowOffset' => ['scope' => 'view', 'type' => 'offset', 'slint' => ['drop-shadow-offset-x', 'drop-shadow-offset-y']],
        'flexDirection' => ['scope' => 'layout', 'type' => 'enum', 'slint' => 'flex-direction',
            'enum' => ['row' => 'row', 'row-reverse' => 'row-reverse', 'column' => 'column', 'column-reverse' => 'column-reverse']],
        'flexWrap' => ['scope' => 'layout', 'type' => 'enum', 'slint' => 'flex-wrap', 'enum' => ['wrap' => 'wrap', 'nowrap' => 'no-wrap', 'wrap-reverse' => 'wrap-reverse']],
        'justifyContent' => ['scope' => 'layout', 'type' => 'enum', 'slint' => 'alignment',
            'enum' => ['flex-start' => 'start', 'flex-end' => 'end', 'center' => 'center', 'space-between' => 'space-between', 'space-around' => 'space-around', 'space-evenly' => 'space-evenly']],
        'alignItems' => ['scope' => 'layout', 'type' => 'enum', 'slint' => 'cross-axis-alignment', 'enum' => self::ALIGN],
        'alignContent' => ['scope' => 'layout', 'type' => 'enum', 'slint' => 'cross-axis-line-alignment',
            'enum' => ['flex-start' => 'start', 'flex-end' => 'end', 'center' => 'center', 'space-between' => 'space-between', 'space-around' => 'space-around', 'stretch' => 'stretch']],
        'gap' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'spacing'],
        'rowGap' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'spacing-vertical'],
        'columnGap' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'spacing-horizontal'],
        'padding' => ['scope' => 'layout', 'type' => 'length', 'slint' => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left']],
        'paddingHorizontal' => ['scope' => 'layout', 'type' => 'length', 'slint' => ['padding-right', 'padding-left']],
        'paddingVertical' => ['scope' => 'layout', 'type' => 'length', 'slint' => ['padding-top', 'padding-bottom']],
        'paddingTop' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'padding-top'],
        'paddingRight' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'padding-right'],
        'paddingBottom' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'padding-bottom'],
        'paddingLeft' => ['scope' => 'layout', 'type' => 'length', 'slint' => 'padding-left'],
        'color' => ['scope' => 'text', 'type' => 'color', 'slint' => 'color'],
        'fontSize' => ['scope' => 'text', 'type' => 'length', 'slint' => 'font-size'],
        'fontWeight' => ['scope' => 'text', 'type' => 'weight', 'slint' => 'font-weight'],
        'textAlign' => ['scope' => 'text', 'type' => 'enum', 'slint' => 'horizontal-alignment', 'enum' => ['left' => 'left', 'center' => 'center', 'right' => 'right']],
    ];

    /** Properties of React Native that Slint can't do, with the reason shown in the error. */
    public const UNSUPPORTED = [
        'flexShrink' => "Slint layouts can't shrink items; use flex (grow) or a fixed size",
        'flexBasis' => "Slint layouts can't shrink items; use flex (grow) or a fixed size",
        'lineHeight' => 'Slint text has no line height',
    ];

    /** Layout properties VStack/HStack take too (the rest need a <View>). */
    public const STACK_LAYOUT = ['gap', 'padding', 'paddingHorizontal', 'paddingVertical', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'justifyContent', 'alignItems'];

    /** What a style property is when it's set only by a condition (no base value). */
    public const DEFAULTS = [
        'background' => 'transparent', 'border-color' => 'transparent', 'border-width' => '0px', 'border-radius' => '0px',
        'opacity' => '1', 'drop-shadow-color' => 'transparent', 'drop-shadow-blur' => '0px',
        'drop-shadow-offset-x' => '0px', 'drop-shadow-offset-y' => '0px', 'spacing' => '0px',
        'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    ];

    /** @return list<string> every name an element may carry as an inline style attribute */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }
}
