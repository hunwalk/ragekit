<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Code;
use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Slint\TextAttribute;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/**
 * <Shortcut keys="Ctrl+F" onPress="find" />: a keyboard shortcut of the window while this screen
 * shows. It draws nothing; the window's key handler gets it (keys a focused input doesn't take
 * reach it, e.g. Ctrl+F or Escape while typing, but not plain letters). Modifiers must match
 * exactly: Ctrl+F doesn't fire for Ctrl+Shift+F.
 */
final class ShortcutCompiler implements ElementCompiler
{
    private const MODIFIERS = ['ctrl' => 'control', 'control' => 'control', 'shift' => 'shift', 'alt' => 'alt', 'meta' => 'meta', 'super' => 'meta', 'cmd' => 'meta'];

    private const NAMED = [
        'escape' => 'Escape', 'esc' => 'Escape', 'enter' => 'Return', 'return' => 'Return', 'tab' => 'Tab',
        'backspace' => 'Backspace', 'delete' => 'Delete', 'space' => 'Space', 'up' => 'UpArrow', 'down' => 'DownArrow',
        'left' => 'LeftArrow', 'right' => 'RightArrow', 'home' => 'Home', 'end' => 'End', 'pageup' => 'PageUp',
        'pagedown' => 'PageDown',
    ];

    public function __construct(private readonly CompileContext $context)
    {
    }

    public function tags(): array
    {
        return ['Shortcut'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $context = $this->context;
        $context->checkAttributes($element, ['keys', 'onPress']);
        if ($element->children !== []) {
            throw TemplateException::at($element, '<Shortcut> has no content');
        }
        if ($context->isGuarded()) {
            throw TemplateException::at($element, "<Shortcut> can't be inside @if or @foreach; check the condition in its handler");
        }
        $keys = $element->attributes['keys'] ?? throw TemplateException::at($element, '<Shortcut> needs keys="…", e.g. keys="Ctrl+F"');
        $handler = $element->attributes['onPress'] ?? throw TemplateException::at($element, '<Shortcut> needs onPress="method": what the keys do');
        $conditions = $context->pageGuard() === '' ? [] : ['(' . $context->pageGuard() . ')'];
        $conditions = [...$conditions, ...self::condition($keys, $element)];
        $context->addShortcut('if (' . implode(' && ', $conditions) . ") {\n" . Code::indent($context->values->handler($handler, $scope, $element), 1) . "    return accept;\n}");
        return '';
    }

    /** @return list<string> Slint conditions on `event` for keys like "Ctrl+Shift+K", "Escape", "Alt+Up" */
    public static function condition(string $keys, Element $element): array
    {
        $parts = array_map('trim', explode('+', $keys));
        $key = array_pop($parts);
        if ($key === '' && str_ends_with($keys, '+')) {
            $key = '+'; // "Ctrl++"
            array_pop($parts);
        }
        $pressed = [];
        foreach ($parts as $part) {
            $modifier = self::MODIFIERS[strtolower($part)] ?? throw TemplateException::at($element, "keys=\"$keys\": unknown modifier '$part' (use Ctrl, Shift, Alt or Meta)");
            $pressed[$modifier] = true;
        }
        $conditions = [];
        foreach (['control', 'shift', 'alt', 'meta'] as $modifier) {
            $conditions[] = (isset($pressed[$modifier]) ? '' : '!') . "event.modifiers.$modifier";
        }
        $lower = strtolower($key);
        if (isset(self::NAMED[$lower])) {
            $conditions[] = 'event.text == Key.' . self::NAMED[$lower];
        } elseif (preg_match('/^f([1-9]|1[0-9]|2[0-4])$/', $lower) === 1) {
            $conditions[] = 'event.text == Key.F' . substr($lower, 1);
        } elseif (strlen($key) === 1 && $key !== '"' && $key !== '\\') {
            // Shift (and Caps Lock) change a letter's case: either one matches.
            $variants = array_unique([strtolower($key), strtoupper($key)]);
            $conditions[] = '(' . implode(' || ', array_map(fn(string $k) => 'event.text == ' . TextAttribute::slintString($k), $variants)) . ')';
        } else {
            throw TemplateException::at($element, "keys=\"$keys\": '$key' isn't a key; use a letter, digit or symbol, F1-F24, or one of: Escape, Enter, Tab, Backspace, Delete, Space, Up, Down, Left, Right, Home, End, PageUp, PageDown");
        }
        return $conditions;
    }
}
