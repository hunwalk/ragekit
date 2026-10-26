<?php

declare(strict_types=1);

namespace RageKit\Tests\Kits;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Slint\Elements\KitElementCompiler;
use RageKit\Cli\Kits\KitRegistry;

final class KitTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testMaterialKitDeclaresCoreElements(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml'], '/rk');
        $material = $kits->get('material');

        self::assertSame('RkWindow', $material->window);
        self::assertStringEndsWith('/kits/material/rk-material.slint', $material->entry);
        self::assertStringEndsWith('/kits/material/library/material.slint', $material->libraries['material']);
        self::assertSame(['Label', 'Button', 'Entry', 'Checkbox', 'Slider', 'Select', 'Image', 'Progress', 'Card', 'Separator', 'TabBar', 'WindowControls',
            'Switch', 'Textarea', 'RadioGroup', 'Toggle', 'Badge', 'Avatar', 'Alert', 'Skeleton', 'Spinner',
            'Sidebar', 'NavList', 'Breadcrumb', 'Pagination', 'AccordionHeader', 'Text', 'Dialog', 'DropdownMenu', 'Toast'], array_keys($material->elements));

        $entry = $material->elements['Entry']->props['value'];
        self::assertSame('string', $entry->type);
        self::assertSame('text', $entry->property);
        self::assertSame('edited', $entry->twoWay);
        self::assertSame(['body', 'title', 'display'], $material->elements['Label']->props['size']->enum);
        self::assertSame(['onClick' => 'clicked'], $material->elements['Button']->events);
    }

    public function testCoreKitsProvideWindowControls(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        foreach (['material', 'shadcn'] as $name) {
            $controls = $kits->get($name)->elements['WindowControls'] ?? null;
            self::assertNotNull($controls, "$name has no WindowControls");
            self::assertSame('bool', $controls->props['maximized']->type);
            self::assertSame(['onMinimize' => 'minimize', 'onToggleMaximize' => 'toggle-maximize', 'onClose' => 'close'], $controls->events);
        }
    }

    public function testShippedKitsAgreeOnTheCoreContract(): void
    {
        $new = ['Switch', 'Textarea', 'RadioGroup', 'Toggle', 'Badge', 'Avatar', 'Alert', 'Skeleton', 'Spinner',
            'Sidebar', 'NavList', 'Breadcrumb', 'Pagination'];
        foreach ($new as $name) {
            self::assertContains($name, KitElementCompiler::CORE, "$name is a core element");
        }
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $describe = function (string $kit, string $element) use ($kits): array {
            $spec = $kits->get($kit)->elements[$element] ?? null;
            self::assertNotNull($spec, "kit $kit lacks core element $element");
            $props = [];
            foreach ($spec->props as $name => $prop) {
                $props[$name] = [$prop->type, $prop->enum, $prop->twoWay !== null];
            }
            ksort($props);
            $events = array_keys($spec->events);
            sort($events);
            $values = $spec->eventValues;
            ksort($values);
            return [$props, $events, $values, $spec->children];
        };
        foreach ([...KitElementCompiler::CORE, 'TabBar', 'WindowControls', 'AccordionHeader', 'Toast'] as $element) {
            self::assertSame($describe('material', $element), $describe('shadcn', $element), "$element differs between kits");
        }
    }

    public function testEntrySubmitsOnReturnInBothKits(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        foreach (['material', 'shadcn'] as $kit) {
            self::assertSame(['onSubmit' => 'submitted'], $kits->get($kit)->elements['Entry']->events, $kit);
        }
    }

    public function testAnEventCanEnableAProperty(): void
    {
        $spec = \RageKit\Cli\Kits\ElementSpec::fromArray(['component' => 'X', 'events' => [
            'onSubmit' => ['callback' => 'submitted', 'enables' => 'submit-on-return'],
            'onSelect' => ['callback' => 'selected', 'value' => 'int'],
        ]], 'test');
        self::assertSame(['onSubmit' => 'submitted', 'onSelect' => 'selected'], $spec->events);
        self::assertSame(['onSubmit' => 'submit-on-return'], $spec->enables);
        self::assertSame(['onSelect' => 'int'], $spec->eventValues, 'an enabling event passes no value');
    }

    public function testUsedKitsAreTheImportedOnes(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', __DIR__ . '/../fixtures/kits/shadcn/kit.yml'], '/rk');
        $shadcn = $kits->get('shadcn');
        self::assertSame([$shadcn], $kits->used([$shadcn->entry]));
        self::assertSame([], $kits->used([]));
    }

    public function testThirdPartyKitWithChildrenAndEnum(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', __DIR__ . '/../fixtures/kits/shadcn/kit.yml'], '/rk');
        $card = $kits->get('shadcn')->elements['Card'];

        self::assertTrue($card->children);
        self::assertSame('string', $card->props['title']->type);
        self::assertSame(['material', 'shadcn'], $kits->names());
        self::assertNull($kits->get('shadcn')->window);
    }

    public function testUnknownKitListsInstalled(): void
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml'], '/rk');
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("unknown kit 'shadcn' (installed: material)");
        $kits->get('shadcn');
    }

    public function testDiscoverFindsComposerKits(): void
    {
        $project = sys_get_temp_dir() . '/rk-project-' . uniqid();
        mkdir("$project/vendor/composer", 0777, true);
        mkdir("$project/vendor/acme/extra", 0777, true);
        copy(__DIR__ . '/../fixtures/kits/extra/kit.yml', "$project/vendor/acme/extra/kit.yml");
        file_put_contents("$project/vendor/composer/installed.json", json_encode(['packages' => [
            ['name' => 'acme/extra', 'install-path' => '../acme/extra', 'extra' => ['ragekit' => ['kit' => 'kit.yml']]],
            ['name' => 'symfony/yaml', 'install-path' => '../symfony/yaml'],
        ]]));

        $kits = KitRegistry::discover(realpath(self::ROOT), $project);

        self::assertSame(['material', 'shadcn', 'extra'], $kits->names());
    }

    public function testRageKitAndKitVariablesIgnoreTheEnvironment(): void
    {
        $dir = sys_get_temp_dir() . '/rk-kitenv-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/kit.yml", "name: k\nentry: k.slint\nlibraries:\n  a: \${KIT}/a.slint\n  b: \${RAGEKIT}/b.slint\n");
        putenv('KIT=/wrong');
        putenv('RAGEKIT=/wrong');
        try {
            $kit = KitRegistry::load(["$dir/kit.yml"], '/rk')->get('k');
        } finally {
            putenv('KIT');
            putenv('RAGEKIT');
        }
        self::assertSame(Paths::normalize((string) realpath($dir)) . '/a.slint', $kit->libraries['a']);
        self::assertSame('/rk/b.slint', $kit->libraries['b']);
    }

    public function testRejectsUnknownPropType(): void
    {
        $file = sys_get_temp_dir() . '/rk-kit-' . uniqid() . '.yml';
        file_put_contents($file, "name: bad\nentry: x.slint\nelements:\n  Button:\n    component: B\n    props:\n      text: colour\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("elements.Button.props.text: unknown type 'colour' (use string, int, float, bool, list, image)");
        KitRegistry::load([$file], '/rk');
    }

    public function testTwoKitFilesWithOneNameInOneFolderFail(): void
    {
        $dir = sys_get_temp_dir() . '/rk-kits-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/a.yml", "name: same\nentry: x.slint\nelements: {}\n");
        file_put_contents("$dir/b.yml", "name: same\nentry: y.slint\nelements: {}\n");
        self::assertSame(['same'], KitRegistry::load(["$dir/a.yml", "$dir/a.yml"], '/rk')->names(), 'the same file twice is fine');
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("two kits are named same: $dir/a.yml and $dir/b.yml");
        KitRegistry::load(["$dir/a.yml", "$dir/b.yml"], '/rk');
    }

    public function testDiscoverFindsProjectKits(): void
    {
        $project = sys_get_temp_dir() . '/rk-proj-' . uniqid();
        mkdir("$project/kits/pics", 0777, true);
        copy(__DIR__ . '/../fixtures/kits/pics/kit.yml', "$project/kits/pics/kit.yml");
        $kits = KitRegistry::discover((string) realpath(__DIR__ . '/../..'), $project);
        self::assertSame(['material', 'shadcn', 'pics'], $kits->names());
    }

    public function testProjectKitWithBuiltinNameFails(): void
    {
        $project = sys_get_temp_dir() . '/rk-proj-' . uniqid();
        mkdir("$project/kits/material", 0777, true);
        file_put_contents("$project/kits/material/kit.yml", "name: material\nentry: x.slint\nelements: {}\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage('two kits are named material');
        KitRegistry::discover((string) realpath(__DIR__ . '/../..'), $project);
    }

    public function testImageTypeCannotBeTwoWayOrEnum(): void
    {
        $file = sys_get_temp_dir() . '/rk-kit-' . uniqid() . '.yml';
        file_put_contents($file, "name: bad\nentry: x.slint\nelements:\n  B:\n    component: B\n    props:\n      icon: { type: image, twoWay: changed }\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessage("image props can't be two-way or enums");
        KitRegistry::load([$file], '/rk');
    }

    public function testImageIsAKnownPropType(): void
    {
        $kits = KitRegistry::load([__DIR__ . '/../fixtures/kits/pics/kit.yml'], '/rk');
        self::assertSame('image', $kits->get('pics')->elements['ImageButton']->props['image']->type);
    }
}
