<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Slint\ScreenModel;
use RageKit\Cli\Slint\ScreensJson;
use RageKit\Cli\Templates\ClassInfo;

final class ScreensJsonTest extends TestCase
{
    public function testDescribesValuesAndActions(): void
    {
        $class = new ClassInfo('Demo\\S', 'S', '/x/S.php', true, '/x/S.rk', ['name' => 'string', 'count' => 'int'], ['label' => 'string'], ['press' => ['string', 'int'], 'label' => []]);
        $model = new ScreenModel($class);
        $name = $model->bind('property', 'name', 'string');
        $model->bind('method', 'label', 'string');
        $model->action('call', 'press', [['literal', 'go'], ['arg', 'key']]);
        $model->action('set', 'name', [], $name);
        $json = json_decode(ScreensJson::emit([$model->window]), true);
        self::assertSame([
            'name' => 'rk-screens', 'window' => null, 'frameless' => false,
            'values' => [],
            'actions' => [],
            'pages' => [[
                'class' => 'Demo\\S', 'name' => 'S', 'page' => 0,
                'values' => [
                    ['id' => 1, 'owner' => 'screen', 'source' => 'property', 'name' => 'name', 'type' => 'string'],
                    ['id' => 2, 'owner' => 'screen', 'source' => 'method', 'name' => 'label', 'type' => 'string'],
                ],
                'actions' => [
                    ['id' => 1, 'owner' => 'screen', 'kind' => 'call', 'target' => 'press', 'args' => [['literal', 'go'], ['arg', 'key']], 'params' => ['string', 'int']],
                    ['id' => 2, 'owner' => 'screen', 'kind' => 'set', 'target' => 'name', 'binding' => 1, 'type' => 'string'],
                ],
            ]],
        ], $json['windows'][0]);
    }
}
