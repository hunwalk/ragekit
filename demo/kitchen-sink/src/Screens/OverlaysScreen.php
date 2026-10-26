<?php

namespace Demo\Screens;

/** A dialog, a dropdown menu, a right-click menu and toasts. */
final class OverlaysScreen extends Page
{
    public const STYLES = [
        'target' => ['backgroundColor' => 'rgba(127, 127, 127, 0.08)', 'borderColor' => 'rgba(127, 127, 127, 0.25)',
            'borderWidth' => 1, 'borderRadius' => 12, 'padding' => 24, 'alignItems' => 'center'],
    ];

    public string $status = 'Nothing yet';
    public bool $confirmDelete = false;

    public static function template(): string
    {
        return 'templates/Screens/Overlays.rk';
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

    public function picked(string $item): void
    {
        $this->status = 'Picked ' . $item;
        $this->toast($item . ' picked');
    }
}
