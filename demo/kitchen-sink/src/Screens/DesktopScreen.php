<?php

namespace Demo\Screens;

use RageKit\Audio\Recorder;
use RageKit\Audio\Sound;
use RageKit\Crypto\Ed25519Key;
use RageKit\Crypto\Ssh;
use RageKit\LocalTime;
use RageKit\Notifications\Notification;
use RageKit\Timer;
use RageKit\Tray\Tray;

/**
 * What a desktop app needs around its UI: timers, notifications, a tray icon, hiding the window,
 * keys and recording. Each card says why when its module (or the desktop) can't do it.
 */
final class DesktopScreen extends Page
{
    public const STYLES = [
        'grid' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => 12],
        'card' => ['width' => 320, 'backgroundColor' => 'rgba(127, 127, 127, 0.08)', 'borderColor' => 'rgba(127, 127, 127, 0.25)',
            'borderWidth' => 1, 'borderRadius' => 12, 'padding' => 14, 'gap' => 8],
    ];

    public string $clock = '';
    public string $notice = '';
    public string $trayLabel = 'Show a tray icon';
    public string $trayStatus = '';
    public string $windowStatus = '';
    public string $key = '';
    public string $recordLabel = 'Record 3 seconds';
    public string $audioStatus = '';
    private ?Timer $tick = null;
    private ?Tray $tray = null;
    private ?Sound $sound = null;
    private bool $recording = false;

    public static function template(): string
    {
        return 'templates/Screens/Desktop.rk';
    }

    public function shown(): void
    {
        $this->clock = LocalTime::format('H:i:s', time());
        $this->tick = Timer::every(1, function (): void {
            $this->clock = LocalTime::format('H:i:s', time());
        });
    }

    public function hidden(): void
    {
        $this->tick?->stop();
    }

    public function notify(): void
    {
        if (!Notification::available()) {
            $this->notice = Notification::why();
            return;
        }
        Notification::show('RageKit', 'Hello from the kitchen sink. Click me.', function (): void {
            $this->notice = 'You clicked the notification';
            $this->app->main()->show();
        });
        $this->notice = 'Sent: look at your desktop';
    }

    public function toggleTray(): void
    {
        if ($this->tray !== null) {
            $this->tray->remove();
            $this->tray = null;
            $this->trayLabel = 'Show a tray icon';
            $this->trayStatus = '';
            return;
        }
        if (!Tray::available()) {
            $this->trayStatus = Tray::why();
            return;
        }
        $this->tray = Tray::show('assets/logo.svg', 'RageKit kitchen sink', function (): void {
            $this->app->main()->show();
        })
            ->item('Show the window', function (): void {
                $this->app->main()->show();
            })
            ->separator()
            ->item('Quit', function (): void {
                $this->app->quit();
            });
        $this->trayLabel = 'Remove the tray icon';
        $this->trayStatus = 'In the panel now; its menu shows the window or quits';
    }

    public function hideForAWhile(): void
    {
        $window = $this->app->main();
        $window->hide();
        Timer::after(3, function () use ($window): void {
            $window->show();
            $this->windowStatus = 'Back after 3 seconds';
        });
    }

    public function newKey(): void
    {
        $key = Ed25519Key::generate();
        $signature = $key->sign('kitchen sink');
        $this->key = Ssh::fingerprint($key) . "\n" . 'Signature verifies: '
            . (Ed25519Key::verify($key->publicKey(), 'kitchen sink', $signature) ? 'yes' : 'no');
    }

    public function record(): void
    {
        if ($this->recording) {
            return;
        }
        try {
            $recorder = Recorder::start();
        } catch (\RuntimeException $e) {
            $this->audioStatus = $e->getMessage();
            return;
        }
        $this->recording = true;
        $this->recordLabel = 'Recording…';
        $this->audioStatus = 'Say something';
        Timer::after(3, function () use ($recorder): void {
            $this->recording = false;
            $this->recordLabel = 'Record 3 seconds';
            try {
                $note = $recorder->stop($this->app->cacheDir() . '/kitchen-sink.wav');
                $this->sound?->unload();
                $this->sound = Sound::load($note->path);
                $this->sound->speed(1.25);
                $this->sound->play();
                $this->audioStatus = 'Playing it back at 1.25×';
            } catch (\RuntimeException $e) {
                $this->audioStatus = $e->getMessage();
            }
        });
    }
}
