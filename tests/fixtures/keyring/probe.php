<?php
// Runs the keyring module's PHP API on its real library (through FFI) and prints one line per step,
// for tests/Modules/KeyringTest.php, which starts it inside a throwaway D-Bus session and keyring.
// Arguments: the library, the ragekit root, and the steps: all (default), store (one entry) or read (it).
declare(strict_types=1);

[$_, $lib, $root] = $argv;
$steps = $argv[3] ?? 'all';
require "$root/vendor/autoload.php";
require "$root/modules/keyring/src/Keyring.php";
require "$root/modules/keyring/src/KeyringException.php";

$ffi = FFI::cdef(<<<'C'
    const char *rkm_keyring_available(void);
    const char *rkm_keyring_set(const char *service, size_t service_len, const char *account, size_t account_len, const char *secret, size_t secret_len);
    const char *rkm_keyring_get(const char *service, size_t service_len, const char *account, size_t account_len);
    const char *rkm_keyring_delete(const char *service, size_t service_len, const char *account, size_t account_len);
    C, $lib);
$GLOBALS['ffi'] = $ffi;

function ragekit_module_available(string $name): bool { return $name === 'keyring'; }
function ragekit_module_error(string $name): string { return ''; }
function keyring_available(): string { return $GLOBALS['ffi']->rkm_keyring_available(); }
function keyring_set(string $s, string $a, string $v): string { return $GLOBALS['ffi']->rkm_keyring_set($s, strlen($s), $a, strlen($a), $v, strlen($v)); }
function keyring_get(string $s, string $a): string { return $GLOBALS['ffi']->rkm_keyring_get($s, strlen($s), $a, strlen($a)); }
function keyring_delete(string $s, string $a): string { return $GLOBALS['ffi']->rkm_keyring_delete($s, strlen($s), $a, strlen($a)); }

use RageKit\Keyring\Keyring;
use RageKit\Keyring\KeyringException;

$step = function (string $name, Closure $fn): void {
    try {
        $value = $fn();
        echo $name, ' ', is_string($value) ? bin2hex($value) : var_export($value, true), "\n";
    } catch (KeyringException $e) {
        echo $name, ' error ', str_replace("\n", ' ', $e->getMessage()), "\n";
    } catch (InvalidArgumentException $e) {
        echo $name, ' invalid ', $e->getMessage(), "\n";
    }
};

if ($steps === 'store') {
    $step('set', fn() => Keyring::set('org.ragekit.test', 'locked', 'kept'));
    exit(0);
}
if ($steps === 'read') {
    $step('get', fn() => Keyring::get('org.ragekit.test', 'locked'));
    $step('missing', fn() => Keyring::get('org.ragekit.test', 'never-stored'));
    exit(0);
}

$step('available', fn() => Keyring::available());
$step('why', fn() => Keyring::why());
$step('missing', fn() => Keyring::get('org.ragekit.test', 'nobody'));
$secret = "\0\t\n\\é" . str_repeat("\xff", 40);
$step('set', fn() => Keyring::set('org.ragekit.test', 'ann', $secret));
$step('set-other', fn() => Keyring::set('org.ragekit.test', 'bob', 'b'));
$step('get', fn() => Keyring::get('org.ragekit.test', 'ann'));
$step('same', fn() => Keyring::get('org.ragekit.test', 'ann') === $secret);
$step('replace', fn() => Keyring::set('org.ragekit.test', 'ann', 'second'));
$step('replaced', fn() => Keyring::get('org.ragekit.test', 'ann'));
$step('other-service', fn() => Keyring::get('org.ragekit.other', 'ann'));
$step('delete', fn() => Keyring::delete('org.ragekit.test', 'ann'));
$step('gone', fn() => Keyring::get('org.ragekit.test', 'ann'));
$step('bob-kept', fn() => Keyring::get('org.ragekit.test', 'bob'));
$step('delete-again', fn() => Keyring::delete('org.ragekit.test', 'ann'));
// Names the stores can't keep apart: refused by the PHP API, and by the library itself.
$step('colon', fn() => Keyring::set('org:ragekit', 'ann', 'x'));
$step('nul', fn() => Keyring::get('org.ragekit.test', "ann\0x"));
$step('not-utf8', fn() => Keyring::get('org.ragekit.test', "ann\xff"));
$step('native-colon', fn() => keyring_get('org:ragekit', 'ann'));
$step('native-nul', fn() => keyring_set('org.ragekit.test', "a\0b", 'x'));
