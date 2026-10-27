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
 * exactly: Ctrl+F doesn't fire for Ctrl+Shift+F. capture="true": the window sees the keys before a
 * focused input does, so keys the input uses (Tab, arrows, Return) reach it too; enabled="{{ bool }}"
 * limits it to while the condition holds (otherwise the keys do what they always do).
 *
 * <PasteImage onPaste="attachPasted" />: Ctrl+V (Shift+Insert, Cmd+V) while the clipboard holds an
 * image runs the handler instead of pasting, even in a focused input (the window sees the keys
 * first: Slint's capture-key-pressed); with text on the clipboard the keys paste as usual.
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
        return ['Shortcut', 'PasteImage'];
    }

    public function compile(Element $element, Scope $scope, string $axis): string
    {
        $context = $this->context;
        $tag = $element->tag;
        if ($element->children !== []) {
            throw TemplateException::at($element, "<$tag> has no content");
        }
        if ($context->isGuarded()) {
            throw TemplateException::at($element, "<$tag> can't be inside @if or @foreach; check the condition in its handler");
        }
        if ($tag === 'PasteImage') {
            return $this->pasteImage($element, $scope);
        }
        $context->checkAttributes($element, ['keys', 'onPress', 'capture', 'enabled']);
        $keys = $element->attributes['keys'] ?? throw TemplateException::at($element, '<Shortcut> needs keys="…", e.g. keys="Ctrl+F"');
        $handler = $element->attributes['onPress'] ?? throw TemplateException::at($element, '<Shortcut> needs onPress="method": what the keys do');
        $conditions = $context->pageGuard() === '' ? [] : ['(' . $context->pageGuard() . ')'];
        $conditions = [...$conditions, ...self::condition($keys, $element)];
        if (isset($element->attributes['enabled'])) {
            $conditions[] = '(' . $context->values->value($element->attributes['enabled'], 'bool', null, $scope, $element, 'enabled') . ')';
        }
        $statement = 'if (' . implode(' && ', $conditions) . ") {\n" . Code::indent($context->values->handler($handler, $scope, $element), 1) . "    return accept;\n}";
        if ($context->flag($element, 'capture')) {
            $context->addCapture($statement); // before a focused input sees the keys (Tab, Up, Return…)
        } else {
            $context->addShortcut($statement);
        }
        return '';
    }

    private function pasteImage(Element $element, Scope $scope): string
    {
        $context = $this->context;
        $context->checkAttributes($element, ['onPaste']);
        $handler = $element->attributes['onPaste'] ?? throw TemplateException::at($element, '<PasteImage> needs onPaste="method": what to do with the image (RageKit\\Clipboard::image() has it)');
        $keys = '((event.modifiers.control || event.modifiers.meta) && !event.modifiers.alt && (event.text == "v" || event.text == "V"))'
            . ' || (event.modifiers.shift && !event.modifiers.control && event.text == Key.Insert)';
        $page = $context->pageGuard() === '' ? '' : '(' . $context->pageGuard() . ') && ';
        // The clipboard is only asked once the keys match (it's a round trip to its owner).
        $context->addCapture('if (' . $page . '(' . $keys . ")) {\n    if (root.rk-has-image()) {\n"
            . Code::indent($context->values->handler($handler, $scope, $element), 2) . "        return accept;\n    }\n}");
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
