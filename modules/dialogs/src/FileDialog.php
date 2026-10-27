<?php

namespace RageKit\Dialogs;

use RageKit\Events;
use RageKit\Modules;

/**
 * The OS's own file dialog (dialogs module). show() returns at once; the app keeps running, and
 * $then gets the chosen paths on the UI thread when the user is done (an empty list = cancelled):
 *
 *     FileDialog::open()
 *         ->title('Send a picture')
 *         ->filter('Images', ['png', 'jpg', 'jpeg', 'gif', 'webp'])
 *         ->show(function (array $paths): void {
 *             if ($paths !== []) {
 *                 $this->attach($paths[0]);
 *             }
 *         });
 *
 * Linux: the desktop's file chooser through xdg-desktop-portal (GNOME, KDE, …). Windows: the common
 * file dialogs. macOS: not yet.
 */
final class FileDialog
{
    private const OPEN = 0;
    private const SAVE = 1;
    private const FOLDER = 2;

    private string $title = '';
    /** @var list<string> "<name>\t<ext> <ext>" */
    private array $filters = [];
    private bool $multiple = false;
    private string $name = '';
    private string $folder = '';

    private function __construct(private int $kind)
    {
    }

    /** Picks a file to open (several with multiple()). */
    public static function open(): FileDialog
    {
        return new FileDialog(self::OPEN);
    }

    /** Picks where to save a file; $name is the suggested file name. */
    public static function save(string $name = ''): FileDialog
    {
        $dialog = new FileDialog(self::SAVE);
        $dialog->name = $name;
        return $dialog;
    }

    /** Picks a folder. */
    public static function folder(): FileDialog
    {
        return new FileDialog(self::FOLDER);
    }

    /** Whether file dialogs can be shown here: the module's library and, on Linux, a portal. */
    public static function available(): bool
    {
        return self::why() === '';
    }

    /** Why file dialogs can't be shown ('' when they can). */
    public static function why(): string
    {
        if (!Modules::available('dialogs')) {
            return 'the dialogs module isn\'t available: ' . \ragekit_module_error('dialogs');
        }
        return \dialogs_available();
    }

    public function title(string $title): FileDialog
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Adds a choice to the dialog's file type list, e.g. filter('Images', ['png', 'jpg']); the first
     * one is selected. Extensions without the dot (case doesn't matter); '*' = every file.
     *
     * @param list<string> $extensions
     */
    public function filter(string $name, array $extensions): FileDialog
    {
        $clean = [];
        foreach ($extensions as $ext) {
            $ext = trim($ext);
            $ext = $ext === '*' ? '*' : ltrim($ext, '*.');
            if ($ext !== '') {
                $clean[] = $ext;
            }
        }
        $this->filters[] = str_replace(["\t", "\n"], ' ', $name) . "\t" . implode(' ', $clean);
        return $this;
    }

    /** Lets the user pick more than one file (open() only). */
    public function multiple(bool $multiple = true): FileDialog
    {
        $this->multiple = $multiple;
        return $this;
    }

    /** The folder it starts in (the OS remembers the last one otherwise). */
    public function in(string $folder): FileDialog
    {
        $this->folder = $folder;
        return $this;
    }

    /**
     * Shows the dialog. $then(list<string> $paths) runs when the user is done: the chosen paths,
     * or [] when they cancelled. $failed(string $error) runs if it couldn't be shown (without it,
     * a failure is thrown from show() or passed to $then as []).
     *
     * @throws \RuntimeException when the dialog couldn't be shown and there's no $failed
     */
    public function show(\Closure $then, ?\Closure $failed = null): void
    {
        $why = self::why();
        if ($why === '') {
            $id = \dialogs_file($this->kind, $this->title, implode("\n", $this->filters), $this->multiple, $this->name, $this->folder);
            if ($id !== 0) {
                Events::on('dialogs', $id, 'done', function (string $data) use ($id, $then): void {
                    Events::off('dialogs', $id);
                    $then($data === '' ? [] : explode("\n", $data));
                });
                Events::on('dialogs', $id, 'error', function (string $data) use ($id, $then, $failed): void {
                    Events::off('dialogs', $id);
                    if ($failed !== null) {
                        $failed($data);
                    } else {
                        $then([]);
                    }
                });
                return;
            }
            $why = \dialogs_error();
        }
        if ($failed === null) {
            throw new \RuntimeException('the file dialog couldn\'t be shown: ' . $why);
        }
        $failed($why);
    }
}
