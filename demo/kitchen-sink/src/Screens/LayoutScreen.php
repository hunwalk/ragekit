<?php

namespace Demo\Screens;

/** <View>s styled like React Native: a card with wrapping tags, a list with a selected row. */
final class LayoutScreen extends Page
{
    public const TAGS = ['Apple', 'Banana', 'Cherry', 'Dragon fruit', 'Small', 'Medium', 'Large'];
    public const TASKS = ['Design', 'Build', 'Test', 'Ship'];

    public const STYLES = [
        'showcase' => ['gap' => 12],
        'card' => ['backgroundColor' => 'rgba(127, 127, 127, 0.08)', 'borderColor' => 'rgba(127, 127, 127, 0.25)',
            'borderWidth' => 1, 'borderRadius' => 12, 'padding' => 16, 'gap' => 8],
        'tags' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => 6],
        'corner' => ['position' => 'absolute', 'top' => 12, 'right' => 12],
        'list' => ['gap' => 4],
        'row' => ['flexDirection' => 'row', 'justifyContent' => 'space-between', 'alignItems' => 'center',
            'paddingHorizontal' => 12, 'paddingVertical' => 8, 'borderRadius' => 8, 'transition' => 150],
        'rowSelected' => ['backgroundColor' => 'rgba(127, 127, 127, 0.18)'],
    ];

    public string $selectedTask = 'Build';

    public static function template(): string
    {
        return 'templates/Screens/Layout.rk';
    }

    public function select(string $task): void
    {
        $this->selectedTask = $task;
    }
}
