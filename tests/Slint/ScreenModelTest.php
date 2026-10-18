<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\ScreenModel;
use RageKit\Cli\Templates\ClassInfo;

final class ScreenModelTest extends TestCase
{
    public function testBindingsAreDedupedAndNumbered(): void
    {
        $model = new ScreenModel(new ClassInfo('A\S', 'S', '/S.php', true, '/S.rk'));
        $count = $model->bind('property', 'count', 'int');
        $again = $model->bind('property', 'count', 'int');
        $greeting = $model->bind('method', 'greeting', 'string');

        self::assertSame($count, $again);
        self::assertSame(1, $count->id);
        self::assertSame('p2', $greeting->slintName());
        self::assertSame(2, count($model->bindings));

        $click = $model->action('call', 'increment', []);
        $set = $model->action('set', 'count', [], $count);
        self::assertSame([1, 2], [$click->id, $set->id]);
        self::assertSame($count, $set->binding);
    }
}
