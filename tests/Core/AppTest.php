<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Screen;

final class StoreReadingScreen extends Screen
{
    public string $user;

    public function __construct()
    {
        $this->user = TestStore::get()->session->userName;
    }
}

/** A root whose constructor doesn't call parent::__construct() (easy to forget). */
final class NoParentStore extends \RageKit\RootStore
{
    public TestSession $session;

    public function __construct()
    {
        $this->session = new TestSession();
    }
}

final class StoreReadingApp extends RecordingApp
{
    protected function firstScreen(): Screen
    {
        return new StoreReadingScreen();
    }
}

final class AppTest extends TestCase
{
    public function testRunOpensTheFirstScreenThenStarts(): void
    {
        $app = RecordingApp::make();
        $app->run();
        self::assertSame(['createStore', 'firstScreen', 'open LoggedScreen Gallery 640x480', 'start', 'run'], $app->recorder->calls);
        self::assertSame(1, $app->main()->id);
    }

    /** The screen is made inside run(), after createStore(): its constructor sees the app's store. */
    public function testStoreExistsBeforeTheFirstScreen(): void
    {
        $app = StoreReadingApp::make();
        $app->root = new TestStore();
        $app->root->session->userName = 'bob';
        $app->run();
        $top = $app->main()->navigator()->top();
        self::assertInstanceOf(StoreReadingScreen::class, $top);
        self::assertSame('bob', $top->user);
    }

    public function testAnAppWithoutStoreGetsAnEmptyRoot(): void
    {
        $app = RecordingApp::make();
        $app->run();
        self::assertNotNull(\RageKit\RootStore::current());
    }

    public function testMainBeforeRunIsAClearError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no main window yet: it opens in run()');
        RecordingApp::make()->main();
    }

    public function testOpenReturnsTheWindow(): void
    {
        $app = RecordingApp::make();
        $app->run();
        $about = $app->open(new LoggedScreen('About'), 'About');
        self::assertSame(2, $about->id);
        self::assertSame('About', $about->navigator()->top()->name);
    }

    public function testClosingTheMainWindowQuits(): void
    {
        $app = RecordingApp::make();
        $app->run();
        $app->open(new LoggedScreen('About'), 'About');
        $app->recorder->calls = [];
        $app->closeRequested($app->main());
        self::assertSame(['quit'], $app->recorder->calls);
    }

    public function testClosingAnotherWindowOnlyClosesIt(): void
    {
        $app = RecordingApp::make();
        $app->run();
        $about = $app->open(new LoggedScreen('About'), 'About');
        $app->recorder->calls = [];
        $app->closeRequested($about);
        self::assertSame(['close 2'], $app->recorder->calls);
    }

    /** The door for tray apps: closing the main window may keep the app running. */
    public function testMainWindowClosedCanBeOverridden(): void
    {
        $app = RecordingApp::make();
        $app->onMainClosed = function () use ($app): void {
            $app->recorder->calls[] = 'hide';
        };
        $app->run();
        $app->recorder->calls = [];
        $app->closeRequested($app->main());
        self::assertSame(['hide'], $app->recorder->calls);
    }

    /** An exception nobody caught is printed through the backend (stderr; Nano PHP has no STDERR). */
    public function testUncaughtExceptionIsPrintedByDefault(): void
    {
        $app = RecordingApp::make();
        $app->exceptionThrown(new \RuntimeException('boom'));
        self::assertSame(['error ragekit: uncaught RuntimeException: boom'], $app->recorder->calls);
    }

    /** A title bar's close button on the main window is the same as the OS's. */
    public function testWindowCloseButtonOnMainQuits(): void
    {
        $app = RecordingApp::make();
        $app->run();
        $app->recorder->calls = [];
        $app->main()->close();
        self::assertSame(['quit'], $app->recorder->calls);
    }

    /** The root createStore() returns is the app's, even if its constructor skipped parent::__construct(). */
    public function testTheRootFromCreateStoreIsRegistered(): void
    {
        new TestStore();
        $app = RecordingApp::make();
        $app->root = new NoParentStore();
        $app->run();
        self::assertSame($app->root, NoParentStore::get());
    }

    /** An app whose AppStore isn't what createStore() returns gets an error that says where to look. */
    public function testGetOfAnotherClassNamesTheLikelyCause(): void
    {
        new \RageKit\RootStore();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("no TestStore yet: return new TestStore() from your App's createStore() (and call parent::__construct() in TestStore's constructor)");
        TestStore::get();
    }
}
