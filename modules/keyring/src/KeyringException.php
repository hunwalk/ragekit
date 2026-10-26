<?php

namespace RageKit\Keyring;

/** The OS's credential store refused or failed (locked and not unlocked, no Secret Service, …). */
final class KeyringException extends \RuntimeException
{
}
