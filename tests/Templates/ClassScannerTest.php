<?php

declare(strict_types=1);

namespace RageKit\Tests\Templates;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\Paths;
use RageKit\Cli\RageKitException;
use RageKit\Cli\Project\SourceFiles;
use RageKit\Cli\Templates\ClassScanner;

final class ClassScannerTest extends TestCase
{
    private const APP = __DIR__ . '/../fixtures/app';

    public function testFindsComponentsWithMembers(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $classes = ClassScanner::scan(SourceFiles::php([self::APP]), $root, (string) realpath(self::APP));

        self::assertSame(['Demo\CounterScreen', 'Demo\History'], array_keys($classes));

        $screen = $classes['Demo\CounterScreen'];
        self::assertTrue($screen->isScreen);
        self::assertSame('CounterScreen', $screen->short);
        self::assertSame(Paths::normalize((string) realpath(self::APP)) . '/templates/counter.rk', $screen->template);
        self::assertSame(['count' => 'int', 'showHistory' => 'bool', 'history' => 'list'], $screen->props);
        self::assertSame('string', $screen->computed['greeting']);
        self::assertSame(['string'], $screen->handlers['press']);
        self::assertSame([], $screen->handlers['increment']);
        self::assertSame([['1', '2'], ['3']], $screen->constants['KEYS']);
        self::assertSame(8, $screen->constants['LIMIT']);

        $history = $classes['Demo\History'];
        self::assertFalse($history->isScreen);
        self::assertSame(Paths::normalize((string) realpath(self::APP)) . '/ui/screens/../shared/history.rk', $history->template);
    }

    public function testBrokenClassReportsPhpError(): void
    {
        $file = sys_get_temp_dir() . '/rk-broken-' . uniqid() . '.php';
        file_put_contents($file, "<?php\nnamespace X;\nfinal class Broken extends \\RageKit\\Screen { public function x(): void { $ ; }\n}\n");
        $this->expectException(RageKitException::class);
        $this->expectExceptionMessageMatches('/reading the app\'s component classes failed:.*syntax error/s');
        ClassScanner::scan([$file], (string) realpath(__DIR__ . '/../..'), '/proj');
    }

    public function testOutputFromAppCodeIsReported(): void
    {
        $dir = sys_get_temp_dir() . '/rk-echo-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/Noisy.php", "<?php\nnamespace N;\necho 'hello from app code';\nfinal class Noisy extends \\RageKit\\Component {}\n");
        try {
            ClassScanner::scan(["$dir/Noisy.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
            self::fail('expected an exception');
        } catch (RageKitException $e) {
            self::assertStringStartsWith("reading the app's component classes failed: unexpected output: hello from app code", $e->getMessage());
        } finally {
            unlink("$dir/Noisy.php");
            rmdir($dir);
        }
    }

    public function testLargeStderrDoesNotDeadlock(): void
    {
        $dir = sys_get_temp_dir() . '/rk-big-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/Big.php", "<?php\nnamespace N;\nfor (\$i = 0; \$i < 20000; \$i++) { fwrite(STDERR, str_repeat('x', 100) . \"\\n\"); }\nfinal class Big extends \\RageKit\\Component {}\n");
        try {
            $classes = ClassScanner::scan(["$dir/Big.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
        } finally {
            unlink("$dir/Big.php");
            rmdir($dir);
        }
        self::assertSame(['N\\Big'], array_keys($classes));
    }

    public function testFailureShowsOnlyTheFatalErrorLine(): void
    {
        $text = "PHP noise\nPHP Fatal error:  Uncaught Error: boom in /a/B.php:3\nStack trace:\n#0 {main}\n";
        self::assertSame('PHP Fatal error:  Uncaught Error: boom in /a/B.php:3', ClassScanner::failure($text, ''));
        self::assertSame('just text', ClassScanner::failure("just text\n", ''));
    }

    public function testResolvesAppTraitsAndInterfaces(): void
    {
        $dir = sys_get_temp_dir() . '/rk-app-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/HasLabel.php', "<?php\nnamespace T;\ninterface HasLabel {}\n");
        file_put_contents($dir . '/Labelled.php', "<?php\nnamespace T;\ntrait Labelled { public string \$label = 'x'; }\n");
        file_put_contents($dir . '/Kind.php', "<?php\nnamespace T;\nenum Kind: string { case A = 'a'; }\n");
        file_put_contents($dir . '/Comp.php', "<?php\nnamespace T;\nfinal class Comp extends \\RageKit\\Component implements HasLabel { use Labelled; public const K = Kind::A; }\n");
        try {
            $classes = ClassScanner::scan(SourceFiles::php([$dir]), (string) realpath(__DIR__ . '/../..'), '/proj');
        } finally {
            array_map('unlink', glob($dir . '/*.php'));
            rmdir($dir);
        }
        self::assertSame(['T\\Comp'], array_keys($classes));
        self::assertSame(['label' => 'string'], $classes['T\\Comp']->props);
    }

    public function testTemplatePathsResolveAgainstProjectRoot(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $classes = ClassScanner::scan(SourceFiles::php([self::APP]), $root, '/proj');

        self::assertSame('/proj/templates/counter.rk', $classes['Demo\CounterScreen']->template);
        self::assertSame('templates/counter.rk', $classes['Demo\CounterScreen']->templateDeclared);
        self::assertSame('/proj/ui/screens/../shared/history.rk', $classes['Demo\History']->template);
    }

    public function testAbsoluteTemplatePathIsKept(): void
    {
        $dir = sys_get_temp_dir() . '/rk-abs-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/A.php", "<?php\nnamespace Abs;\nfinal class A extends \\RageKit\\Screen { public static function template(): string { return '/abs/a.rk'; } }\n");
        $classes = ClassScanner::scan(["$dir/A.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
        self::assertSame('/abs/a.rk', $classes['Abs\A']->template);
    }

    public function testUndeclaredTemplateIsEmpty(): void
    {
        $dir = sys_get_temp_dir() . '/rk-undeclared-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/B.php", "<?php\nnamespace Und;\nfinal class B extends \\RageKit\\Screen {}\n");
        $classes = ClassScanner::scan(["$dir/B.php"], (string) realpath(__DIR__ . '/../..'), '/proj');
        self::assertSame('', $classes['Und\B']->template);
        self::assertSame('', $classes['Und\B']->templateDeclared);
    }
}
