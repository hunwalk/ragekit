<?php

namespace Demo;

use RageKit\Screen;

/** Every core element in one window, grouped in tabs. */
final class KitchenSinkScreen extends Screen
{
    public const FRUITS = ['Apple', 'Banana', 'Cherry', 'Dragon fruit'];
    public const SIZES = ['Small', 'Medium', 'Large'];
    public const TASKS = ['Design', 'Build', 'Test', 'Ship'];
    public const SECTIONS = ['Home', 'Projects', 'Settings'];

    /** React Native-style styles: the Layout tab's <View>s. */
    public const STYLES = [
        'showcase' => ['gap' => 12],
        'card' => ['backgroundColor' => 'rgba(127, 127, 127, 0.08)', 'borderColor' => 'rgba(127, 127, 127, 0.25)',
            'borderWidth' => 1, 'borderRadius' => 12, 'padding' => 16, 'gap' => 8],
        'tags' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => 6],
        'corner' => ['position' => 'absolute', 'top' => 12, 'right' => 12],
        'list' => ['gap' => 4],
        'row' => ['flexDirection' => 'row', 'justifyContent' => 'space-between', 'alignItems' => 'center',
            'paddingHorizontal' => 12, 'paddingVertical' => 4, 'borderRadius' => 8, 'backgroundColor' => 'transparent', 'transition' => 150],
        'rowSelected' => ['backgroundColor' => 'rgba(127, 127, 127, 0.18)'],
    ];

    public int $tab = 0;
    public string $status = 'Ready';
    public string $name = '';
    public bool $subscribed = false;
    public float $volume = 40.0;
    public string $fruit = '';
    public bool $notifications = true;
    public string $notes = '';
    public string $size = 'Medium';
    public bool $bold = false;
    public string $selectedTask = 'Build';
    public string $section = 'Projects';
    public int $page = 1;
    public int $open = 0;
    public bool $confirmDelete = false;

    public static function template(): string
    {
        return 'templates/KitchenSink.rk';
    }

    public static function window(): string
    {
        return KitchenSinkWindow::class;
    }

    public function preferences(): string
    {
        return $this->size . ' · ' . ($this->bold ? 'bold' : 'regular') . ' · notifications ' . ($this->notifications ? 'on' : 'off')
            . ' · ' . strlen($this->notes) . ' characters of notes';
    }

    public function volumeText(): string
    {
        return 'Volume: ' . (int) round($this->volume);
    }

    public function progress(): float
    {
        return $this->volume / 100;
    }

    public function summary(): string
    {
        $who = $this->name === '' ? 'Anonymous' : $this->name;
        $fruit = $this->fruit === '' ? 'no fruit' : $this->fruit;
        return $who . ' · ' . $fruit . ' · ' . ($this->subscribed ? 'subscribed' : 'not subscribed');
    }

    /** @return list<string> the breadcrumb: Home, then the current section */
    public function trail(): array
    {
        return $this->section === 'Home' ? ['Home'] : ['Home', $this->section];
    }

    public function goTo(string $crumb): void
    {
        $this->section = $crumb;
        $this->status = 'Went to ' . $crumb;
    }

    public function select(string $task): void
    {
        $this->selectedTask = $task;
        $this->status = 'Selected ' . $task;
    }

    public function askDelete(): void
    {
        $this->confirmDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmDelete = false;
        $this->status = 'Kept the project';
    }

    public function deleteProject(): void
    {
        $this->confirmDelete = false;
        $this->status = 'Deleted the project';
        $this->toast('Project deleted');
    }

    public function save(): void
    {
        $this->status = 'Saved';
        $this->toast('Saved');
    }

    public function rename(): void
    {
        $this->toast('Rename picked');
    }

    public function duplicate(): void
    {
        $this->toast('Duplicate picked');
    }

        public function press(string $variant): void
    {
        $this->status = 'Clicked the ' . $variant . ' button';
    }
}
