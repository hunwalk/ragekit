<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Events;
use RageKit\Links;

require_once __DIR__ . '/NavigatorTest.php'; // LoggedScreen, RecordingApp's default first screen

/** App links: single instance, links reaching the app, registering a scheme. */
final class LinksTest extends TestCase
{
    protected function tearDown(): void
    {
        Events::off('@links', 0);
        Links::attach(null);
    }

    public function testAnAppWithoutLinksDoesntClaimAnything(): void
    {
        $app = RecordingApp::make();
        $app->run();
        self::assertNotContains('claim', $app->recorder->calls);
    }

    public function testASecondLaunchHandsOverAndEnds(): void
    {
        $app = RecordingApp::make();
        $app->recorder->links = ['myapp'];
        $app->recorder->first = false;
        $app->run();
        self::assertSame(['claim'], $app->recorder->calls, 'no store, no window: the running instance got the link');
    }

    public function testTheRunningInstanceGetsLinksAndComesToTheFront(): void
    {
        $app = RecordingApp::make();
        $app->recorder->links = ['myapp'];
        $app->run();
        self::assertSame('claim', $app->recorder->calls[0]);
        $app->recorder->calls = [];
        Events::deliver('@links', 0, 'open', 'myapp://auth?code=1');
        Events::deliver('@links', 0, 'focus', '');
        self::assertSame(['raise 1', 'link myapp://auth?code=1', 'raise 1'], $app->recorder->calls);
    }

    public function testQuery(): void
    {
        self::assertSame(['code' => 'a b', 'state' => 'x y', 'flag' => '', 'e' => 'é&='], Links::query('myapp://auth?code=a%20b&state=x+y&flag&e=%C3%A9%26%3D&code=second#frag'));
        self::assertSame([], Links::query('myapp://auth'));
        self::assertSame([], Links::query('myapp://auth?'));
    }

    public function testOpenInBrowserOnlyOpensWebPages(): void
    {
        $backend = new RecordingBackend();
        Links::attach($backend);
        Links::openInBrowser('https://example.com/a?b=c');
        self::assertSame(['browse https://example.com/a?b=c'], $backend->calls);
        $this->expectException(\InvalidArgumentException::class);
        Links::openInBrowser('file:///etc/passwd');
    }

    public function testAHiddenMainWindowIsShownAgain(): void
    {
        $app = RecordingApp::make();
        $app->recorder->links = ['myapp'];
        $app->run();
        $app->main()->hide(); // living in the tray
        $app->recorder->calls = [];
        Events::deliver('@links', 0, 'focus', '');
        self::assertSame(['show 1', 'raise 1'], $app->recorder->calls);
        self::assertTrue($app->main()->visible());
    }

    public function testRegisterOnlyDeclaredSchemes(): void
    {
        $backend = new RecordingBackend();
        $backend->links = ['myapp'];
        Links::attach($backend);
        self::assertSame(['myapp'], Links::schemes());
        Links::register('myapp');
        self::assertSame(['register myapp'], $backend->calls);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'other' isn't one of the app's links: declare it in project.yml (ragekit: { links: [other] })");
        Links::register('other');
    }

    public function testRegisterReportsTheOsError(): void
    {
        $backend = new RecordingBackend();
        $backend->links = ['myapp'];
        $backend->registerError = "can't write /x/mimeapps.list";
        Links::attach($backend);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("couldn't register myapp: links: can't write /x/mimeapps.list");
        Links::register('myapp');
    }
}
