<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Project\Project;

final class ProjectTest extends TestCase
{
    public function testKitOverrideReplacesProjectOption(): void
    {
        $dir = sys_get_temp_dir() . '/rk-project-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/project.yml", "name: x\nragekit:\n  backend: slint\n  kit: shadcn\n");
        self::assertSame('shadcn', Project::load($dir)->options['kit']);
        self::assertSame('material', Project::load($dir, null, 'material')->options['kit']);
    }

    public function testWithOptionLeavesTheOriginalAlone(): void
    {
        $dir = sys_get_temp_dir() . '/rk-project-' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/project.yml", "name: x\nragekit:\n  kit: shadcn\n");
        $project = Project::load($dir);
        $nano = $project->withOption('nano', true);
        self::assertTrue($nano->options['nano']);
        self::assertSame('shadcn', $nano->options['kit']);
        self::assertArrayNotHasKey('nano', $project->options);
    }
}
