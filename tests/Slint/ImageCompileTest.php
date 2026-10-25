<?php

declare(strict_types=1);

namespace RageKit\Tests\Slint;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\Slint\BinderEmitter;
use RageKit\Cli\Slint\GlueEmitter;
use RageKit\Cli\Slint\ScreensJson;
use RageKit\Cli\Slint\CompiledViews;
use RageKit\Cli\Slint\SlintCompiler;
use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\TemplateException;
use RageKit\Tests\Support\InstalledToolchain;

/** Images in templates: $assets->group->name, sources bound to PHP values, fallback. */
final class ImageCompileTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-image-' . uniqid();
        mkdir("$this->dir/assets", 0777, true);
        // A 1×1 PNG, so slint-compiler can embed it.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        file_put_contents("$this->dir/assets/logo.png", $png);
        file_put_contents("$this->dir/assets/sun.png", $png);
    }

    private function app(): ClassInfo
    {
        return new ClassInfo('Demo\MyApp', 'MyApp', "$this->dir/MyApp.php", false, '', isApp: true, assets: [
            ['group' => 'images', 'class' => 'Demo\Images', 'found' => true, 'constants' => ['LOGO' => 'assets/logo.png']],
            ['group' => 'icons', 'class' => 'Demo\Icons', 'found' => true, 'constants' => ['SUN' => 'assets/sun.png']],
        ]);
    }

    /** @param array<string, string> $props */
    private function screen(string $rk, array $props = ['cover' => 'string', 'count' => 'int'], array $computed = [], string $short = 'Home'): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", true, "$this->dir/$short.rk", props: $props, computed: $computed, handlers: array_map(fn() => [], $computed));
    }

    private function child(string $rk, string $short = 'Badge'): ClassInfo
    {
        file_put_contents("$this->dir/$short.rk", $rk);
        return new ClassInfo("Demo\\$short", $short, "$this->dir/$short.php", false, "$this->dir/$short.rk", props: ['url' => 'string']);
    }

    /** @param list<ClassInfo> $classes */
    private function compile(array $classes, bool $withApp = true, string $kit = 'shadcn', bool $slint = false): CompiledViews
    {
        $kits = KitRegistry::load([self::ROOT . '/kits/material/kit.yml', self::ROOT . '/kits/shadcn/kit.yml'], '/rk');
        $byName = [];
        foreach ([...($withApp ? [$this->app()] : []), ...$classes] as $class) {
            $byName[$class->name] = $class;
        }
        $out = (new SlintCompiler($kits, $kit, $this->dir))->compile($byName);
        if ($slint) {
            $compiler = InstalledToolchain::slintHome() . '/bin/slint-compiler';
            if (!is_file($compiler)) {
                self::markTestSkipped('needs the Slint toolchain');
            }
            file_put_contents("$this->dir/app.slint", $out->slint);
            $command = [$compiler, "$this->dir/app.slint", '-f', 'cpp', '--embed-resources=embed-files', '-o', "$this->dir/app.h"];
            foreach ($out->libraries as $name => $path) {
                array_push($command, '-L', "$name=$path");
            }
            exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines));
        }
        return $out;
    }

    private function fails(string $message, array $classes): void
    {
        try {
            $this->compile($classes);
            self::fail('expected an error');
        } catch (TemplateException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testAssetSourceIsEmbedded(): void
    {
        $out = $this->compile([$this->screen('<Image source="{{ $assets->images->logo }}" />')]);
        self::assertStringContainsString('source: @image-url("' . $this->dir . '/assets/logo.png");', $out->slint);
        self::assertContains(realpath("$this->dir/assets/logo.png"), array_map('realpath', $out->images));
    }

    public function testUnknownAsset(): void
    {
        $this->fails("\$assets->images->lgo: no image 'lgo' (images has: logo)", [$this->screen('<Image source="{{ $assets->images->lgo }}" />')]);
        $this->fails("\$assets->photos: no asset group 'photos' (groups: images, icons)", [$this->screen('<Image source="{{ $assets->photos->x }}" />')]);
    }

    public function testAssetsWithoutRegistry(): void
    {
        try {
            $this->compile([$this->screen('<Image source="{{ $assets->images->logo }}" />')], withApp: false);
            self::fail('expected an error');
        } catch (TemplateException $e) {
            self::assertStringContainsString('$assets needs images registered by your App: protected function assets(): array', $e->getMessage());
        }
    }

    public function testAssetsOutsideImage(): void
    {
        $this->fails('$assets->images->logo is an image; use it in an image attribute like source=', [$this->screen('<Label text="{{ $assets->images->logo }}" />')]);
    }

    public function testBoundPropertySource(): void
    {
        $out = $this->compile([$this->screen("<VStack>\n<Image source=\"{{ \$cover }}\" />\n<Label text=\"{{ \$cover }}\" />\n</VStack>")]);
        $page = $out->windows[0]->pages[0];
        $image = $page->bindings['screen:property:cover:image'];
        $text = $page->bindings['screen:property:cover'];
        self::assertSame('image', $image->type);
        self::assertSame('string', $text->type);
        self::assertStringContainsString('in property <image> ' . $image->slintName() . ';', $out->slint);
        self::assertStringContainsString('source: root.' . $image->slintName() . ';', $out->slint);
    }

    public function testBoundMethodSource(): void
    {
        $out = $this->compile([$this->screen('<Avatar source="{{ cover() }}" text="A" />', [], ['cover' => 'string'])]);
        $image = $out->windows[0]->pages[0]->bindings['screen:method:cover:image'];
        self::assertSame('image', $image->type);
    }

    public function testBoundSourceMustBeOneStringReference(): void
    {
        $this->fails('image sources take one property, method or $store value; for anything else add a method', [$this->screen('<Image source="{{ $cover . \'x\' }}" />')]);
        $this->fails("expected a string, '\$count' is an int", [$this->screen('<Image source="{{ $count }}" />')]);
    }

    private function devices(): ClassInfo
    {
        return new ClassInfo('Demo\Devices', 'Devices', "$this->dir/Devices.php", false, '', isCollection: true, of: 'Demo\Device', ofFound: true,
            props: ['name' => 'string', 'image' => 'string', 'battery' => 'float']);
    }

    public function testRowImageField(): void
    {
        $rk = "<VStack>\n@foreach (\$devices as \$d)\n<Image source=\"{{ \$d->image }}\" fallback=\"{{ \$assets->images->logo }}\" />\n@endforeach\n</VStack>";
        $out = $this->compile([$this->devices(), $this->screen($rk, ['devices' => 'Demo\Devices'])], slint: true);
        self::assertStringContainsString("export struct RkRowDevice {\n    f0: image,\n}", $out->slint);
        self::assertStringContainsString("source: d.f0.width > 0 ? d.f0 : @image-url(\"$this->dir/assets/logo.png\");", $out->slint);
    }

    public function testRowMemberAsTextAndImage(): void
    {
        $rk = "<VStack>\n@foreach (\$devices as \$d)\n<VStack>\n<Label text=\"{{ \$d->image }}\" />\n<Image source=\"{{ \$d->image }}\" />\n</VStack>\n@endforeach\n</VStack>";
        $out = $this->compile([$this->devices(), $this->screen($rk, ['devices' => 'Demo\Devices'])]);
        self::assertStringContainsString("export struct RkRowDevice {\n    f0: string,\n    f1: image,\n}", $out->slint);
    }

    public function testListOfStringsAsImages(): void
    {
        $out = $this->compile([$this->screen("<VStack>\n@foreach (\$photos as \$p)\n<Image source=\"{{ \$p }}\" />\n@endforeach\n</VStack>", ['photos' => 'list'])], slint: true);
        $page = $out->windows[0]->pages[0];
        $list = $page->bindings['screen:property:photos']->slintName();
        $images = $page->bindings['screen:property:photos:images'];
        self::assertSame('images', $images->type);
        self::assertStringContainsString("in property <[image]> {$images->slintName()};", $out->slint);
        self::assertStringContainsString("for p[rk-i] in root.$list :", $out->slint);
        self::assertStringContainsString("source: root.{$images->slintName()}[rk-i];", $out->slint);
        $named = $this->compile([$this->screen("<VStack>\n@foreach (\$photos as \$i => \$p)\n<Image source=\"{{ \$p }}\" />\n@endforeach\n</VStack>", ['photos' => 'list'], short: 'Named')]);
        self::assertStringContainsString('[i];', $named->slint);
        self::assertStringNotContainsString('[rk-i]', $named->slint);
        $plain = $this->compile([$this->screen("<VStack>\n@foreach (\$photos as \$p)\n<Label text=\"{{ \$p }}\" />\n@endforeach\n</VStack>", ['photos' => 'list'], short: 'Plain')]);
        self::assertStringNotContainsString('[rk-i]', $plain->slint);
    }

    public function testImagesInConstantLoop(): void
    {
        $screen = new ClassInfo('Demo\Home', 'Home', "$this->dir/Home.php", true, "$this->dir/Home.rk", constants: ['PHOTOS' => ['a.png']]);
        file_put_contents("$this->dir/Home.rk", "<VStack>\n@foreach (self::PHOTOS as \$p)\n<Image source=\"{{ \$p }}\" />\n@endforeach\n</VStack>");
        $this->fails("images in a loop over a constant aren't supported; use a property or method", [$screen]);
    }

    public function testRowImageNeedsString(): void
    {
        $rk = "<VStack>\n@foreach (\$devices as \$d)\n<Image source=\"{{ \$d->battery }}\" />\n@endforeach\n</VStack>";
        $this->fails('$d->battery is a float; image sources need a string', [$this->devices(), $this->screen($rk, ['devices' => 'Demo\Devices'])]);
    }

    public function testChildComponents(): void
    {
        $this->compile([$this->screen('<Badge />'), $this->child('<Image source="{{ $assets->icons->sun }}" />')]);
        $this->fails("child components can't show images bound to PHP values yet", [$this->screen('<Badge />'), $this->child('<Image source="{{ $url }}" />')]);
    }

    public function testFallback(): void
    {
        $out = $this->compile([$this->screen('<Image source="{{ $cover }}" fallback="{{ $assets->images->logo }}" />')]);
        $p = $out->windows[0]->pages[0]->bindings['screen:property:cover:image']->slintName();
        self::assertStringContainsString("source: root.$p.width > 0 ? root.$p : @image-url(\"$this->dir/assets/logo.png\");", $out->slint);
        $this->fails('fallback takes a file path or $assets->…, not a bound value', [$this->screen('<Image source="{{ $cover }}" fallback="{{ $cover }}" />')]);
    }

    public function testRkAssetsGlobal(): void
    {
        $out = $this->compile([$this->screen('<Image source="{{ $cover }}" />')], slint: true);
        self::assertStringContainsString("export global RkAssets {\n    out property <[image]> list: [@image-url(\"$this->dir/assets/logo.png\"), @image-url(\"$this->dir/assets/sun.png\")];\n}", $out->slint);
        self::assertSame(['assets/logo.png', 'assets/sun.png'], $out->assets->paths());
        $none = $this->compile([$this->screen('<Image source="{{ $cover }}" />')], withApp: false);
        self::assertStringNotContainsString('RkAssets', $none->slint);
    }

    public function testFallbackAndMaterialCompileWithSlint(): void
    {
        $this->compile([$this->screen('<Image source="{{ $cover }}" fallback="assets/logo.png" />')], kit: 'material', slint: true);
        $this->addToAssertionCount(1);
    }

    public function testAssetsIsAReservedLoopVariable(): void
    {
        $this->fails("\$assets is reserved for the app's assets; name the loop variable something else", [$this->screen("<VStack>\n@foreach (\$photos as \$assets)\n<Label text=\"x\" />\n@endforeach\n</VStack>", ['photos' => 'list'])]);
    }

    public function testBinderWrapsImageValuesAndKnowsAssets(): void
    {
        $out = $this->compile([$this->screen("<VStack>\n<Image source=\"{{ \$cover }}\" />\n<Label text=\"{{ \$cover }}\" />\n</VStack>")]);
        $page = $out->windows[0]->pages[0];
        $image = $page->bindings['screen:property:cover:image']->id;
        $text = $page->bindings['screen:property:cover']->id;
        $php = BinderEmitter::emit($out->windows, null, $out->assets, []);
        self::assertStringContainsString("$image => new \\RageKit\\Slint\\ImageValue((string) \$s->cover),", $php);
        self::assertStringContainsString("$text => \$s->cover,", $php);
        self::assertStringContainsString("if (\$source === 'assets/logo.png') {\n            return 0;", $php);
        self::assertStringContainsString("if (\$source === 'assets/sun.png') {\n            return 1;", $php);
        self::assertStringNotContainsString('Http', $php);
        $file = "$this->dir/binder.php";
        file_put_contents($file, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
    }

    public function testBinderFetchesWithTheHttpModule(): void
    {
        $out = $this->compile([$this->screen('<Image source="{{ $cover }}" />')]);
        $php = BinderEmitter::emit($out->windows, null, $out->assets, ['http']);
        self::assertStringContainsString('\\RageKit\\Http\\Http::get($url)->saveTo($file)->send(', $php);
        $none = BinderEmitter::emit($out->windows, null, null, []);
        self::assertStringContainsString("return -1;", $none);
    }

    public function testGlueSetsImagesAndReadsAssets(): void
    {
        $out = $this->compile([$this->screen('<Image source="{{ $cover }}" />')]);
        $p = $out->windows[0]->pages[0]->bindings['screen:property:cover:image'];
        $cc = GlueEmitter::emit($out->windows, true);
        self::assertStringContainsString('void set_image(int id, const slint::Image &value) override', $cc);
        self::assertStringContainsString("case {$p->id}: handle_->set_{$p->slintName()}(value); break;", $cc);
        self::assertStringContainsString('handle_->global<RkAssets>().get_list()', $cc);
        self::assertStringNotContainsString('RkAssets', GlueEmitter::emit($out->windows, false));
    }

    public function testScreensJsonKeepsTheImageType(): void
    {
        $out = $this->compile([$this->screen('<Image source="{{ $cover }}" />')]);
        $json = json_decode(ScreensJson::emit($out->windows), true);
        self::assertSame('image', $json['windows'][0]['pages'][0]['values'][0]['type']);
    }
}
