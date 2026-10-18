<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

use RageKit\Cli\Build\BuildContext;

/** Turns the app's templates into code for one backend (backend.yml `ragekit: views: generator:`). */
interface ViewGenerator
{
    public function generate(ViewProject $project, BuildContext $context): ViewResult;
}
