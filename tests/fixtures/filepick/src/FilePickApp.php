<?php

namespace Fix;

use RageKit\App;
use RageKit\Dialogs\FileDialog;
use RageKit\Screen;

/** Opens two files, gets cancelled once, saves once, picks a folder, then quits. */
final class FilePickApp extends App
{
    private ResultScreen $screen;

    protected function firstScreen(): Screen
    {
        return $this->screen = new ResultScreen();
    }

    protected function title(): string
    {
        return 'filepick';
    }

    protected function start(): void
    {
        echo 'available ' . (FileDialog::available() ? 'yes' : 'no: ' . FileDialog::why()) . "\n";
        FileDialog::open()
            ->title('Send a picture')
            ->filter('Images', ['png', '.jpg'])
            ->filter('All files', ['*'])
            ->multiple()
            ->show(function (array $paths): void {
                echo 'opened ' . implode(' | ', $paths) . "\n";
                $this->screen->text = 'opened ' . count($paths);
                FileDialog::open()->show(function (array $paths): void {
                    echo 'cancelled ' . count($paths) . "\n";
                    FileDialog::save('voice note.wav')->in('/tmp')->filter('Sound', ['wav'])->show(function (array $paths): void {
                        echo 'saved ' . implode(' | ', $paths) . "\n";
                        FileDialog::folder()->show(function (array $paths): void {
                            echo 'folder ' . implode(' | ', $paths) . "\n";
                            $this->quit();
                        });
                    });
                });
            });
    }
}
