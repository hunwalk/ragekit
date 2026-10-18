<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Component;
use RageKit\Screen;

final class ComponentTest extends TestCase
{
    public function testTemplateIsEmptyUntilAComponentDeclaresIt(): void
    {
        $screen = new class extends Screen {
        };
        self::assertInstanceOf(Component::class, $screen);
        self::assertSame('', $screen::template());
    }

    public function testTemplateCanBeOverridden(): void
    {
        $component = new class extends Component {
            public static function template(): string
            {
                return 'templates/custom.rk';
            }
        };
        self::assertSame('templates/custom.rk', $component::template());
    }
}
