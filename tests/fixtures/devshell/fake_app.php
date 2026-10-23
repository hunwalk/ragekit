<?php
// Fake app for ShellTest: speaks the dev protocol on fds 3/4 like src/Slint/Dev does and
// logs every reply/message it receives to $argv[1]. Modes ($argv[2]): normal, nope, exit3,
// open-exit3 (opens a window, closes it, exits 3), module, framed (a Window subclass's window: maximize, minimize).
require dirname(__DIR__, 3) . '/src/Slint/Dev/Protocol.php';

use RageKit\Slint\Dev\Protocol;

$log = $argv[1];
$mode = $argv[2] ?? 'normal';
if ($mode === 'exit3') {
    exit(3);
}
$in = fopen('php://fd/3', 'r');
$out = fopen('php://fd/4', 'w');
if ($mode === 'module') {
    // Module calls instead of a window: each reply is logged, then the app quits.
    foreach ([['call', 'demo_echo', "a\tb", '2'], ['call', 'demo_nope'], ['module', 'demo'], ['module', 'nope'], ['module-error', 'demo']] as $request) {
        fwrite($out, Protocol::encode(...$request));
        file_put_contents($log, implode('|', Protocol::decode((string) fgets($in))) . "\n", FILE_APPEND);
    }
    fwrite($out, Protocol::encode('quit'));
    exit(0);
}
// S and V share the window component rk-screens (pages 0 and 1); F is rk-screens-L (Window subclass L).
fwrite($out, Protocol::encode('open', match ($mode) { 'nope' => 'Nope', 'framed' => 'rk-screens-L', default => 'rk-screens' }, 'Fake', '300', '200'));
$reply = Protocol::decode((string) fgets($in));
file_put_contents($log, implode(' ', $reply) . "\n", FILE_APPEND);
if ($mode === 'open-exit3') {
    fwrite($out, Protocol::encode('close', $reply[1]));
    exit(3);
}
if ($mode === 'framed') {
    // A framed window's controls from PHP (Window::toggleMaximize(), minimize()).
    fwrite($out, Protocol::encode('maximize', $reply[1], '1'));
    fwrite($out, Protocol::encode('minimize', $reply[1]));
    fwrite($out, Protocol::encode('run'));
    while (($line = fgets($in)) !== false) {
        file_put_contents($log, implode(' ', Protocol::decode($line)) . "\n", FILE_APPEND);
    }
    exit(0);
}
fwrite($out, $mode === 'view'
    ? Protocol::encode('set-int', $reply[1], 'rk-page', '1') . Protocol::encode('set-list', $reply[1], 'p2', 'php', 'slint', 'desktop')
    : Protocol::encode('set-string', $reply[1], 'p1', "tab\there"));
fwrite($out, Protocol::encode('run'));
while (($line = fgets($in)) !== false) {
    file_put_contents($log, implode(' ', Protocol::decode($line)) . "\n", FILE_APPEND);
}
