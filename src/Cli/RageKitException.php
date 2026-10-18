<?php

declare(strict_types=1);

namespace RageKit\Cli;

/** A build problem the user can fix; printed as one `ragekit: …` line instead of a stack trace. */
class RageKitException extends \RuntimeException
{
}
