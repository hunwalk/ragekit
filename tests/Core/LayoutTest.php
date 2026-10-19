<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\App;
use RageKit\Backend;
use RageKit\Layout;
use RageKit\Screen;

final class LayoutTest extends TestCase
{
    private RecordingBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new RecordingBackend();
    }

    /** An App on the recording backend (App's constructor asks the compiled-in backend). */
    private function app(): App
    {
        $app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(App::class, 'backend'))->setValue($app, $this->backend);
        return $app;
    }

    private function layout(): Layout
    {
        $layout = new class extends Layout {
        };
        $layout->mount($this->app());
        $layout->attach(new class extends Screen {
        }, 7);
        return $layout;
    }

    public function testMinimizeAsksBackendForItsWindow(): void
    {
        $this->layout()->minimize();
        self::assertSame(['minimize 7'], $this->backend->calls);
    }

    public function testToggleMaximizeFlipsFlagAndBackend(): void
    {
        $layout = $this->layout();
        $layout->toggleMaximize();
        self::assertTrue($layout->maximized);
        $layout->toggleMaximize();
        self::assertFalse($layout->maximized);
        self::assertSame(['maximize 7 1', 'maximize 7 0'], $this->backend->calls);
    }

    public function testCloseClosesItsWindow(): void
    {
        $this->layout()->close();
        self::assertSame(['close 7'], $this->backend->calls);
    }

    public function testScreenDefaultsToNoLayout(): void
    {
        $screen = new class extends Screen {
        };
        self::assertSame('', $screen::layout());
        self::assertNull($screen->layoutObject());
        $layout = new class extends Layout {
        };
        $screen->useLayout($layout);
        self::assertSame($layout, $screen->layoutObject());
    }

    public function testLayoutDeclaresNoContextByDefault(): void
    {
        $layout = new class extends Layout {
        };
        self::assertSame('', $layout::context());
    }
}

final class RecordingBackend implements Backend
{
    /** @var list<string> */
    public array $calls = [];

    public function open(App $app, Screen $screen, string $title, int $width, int $height): int
    {
        return 1;
    }

    public function close(int $window): void
    {
        $this->calls[] = "close $window";
    }

    public function minimize(int $window): void
    {
        $this->calls[] = "minimize $window";
    }

    public function setMaximized(int $window, bool $maximized): void
    {
        $this->calls[] = "maximize $window " . ($maximized ? '1' : '0');
    }

    public function quit(): void
    {
    }

    public function run(): void
    {
    }

    public function appDir(): string
    {
        return '/app';
    }
}
