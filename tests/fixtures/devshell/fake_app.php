<?php
// Fake app for ShellTest: speaks the dev protocol over the shell's loopback connection like
// src/Slint/Dev does and logs every reply/message it receives to $argv[1]. Modes ($argv[2]): normal, nope, exit3,
// open-exit3 (opens a window, closes it, exits 3), module, framed (a Window subclass's window: maximize, minimize).
require dirname(__DIR__, 3) . '/src/Slint/Dev/Protocol.php';
require dirname(__DIR__, 3) . '/src/Slint/Dev/Channel.php';

use RageKit\Slint\Dev\Channel;

$log = $argv[1];
$mode = $argv[2] ?? 'normal';
if ($mode === 'exit3') {
    exit(3);
}
$shell = Channel::connect();
$received = function (string $separator) use ($shell, $log): ?array {
    $message = $shell->next();
    if ($message !== null) {
        file_put_contents($log, implode($separator, $message) . "\n", FILE_APPEND);
    }
    return $message;
};
if ($mode === 'module') {
    // Module calls instead of a window: each reply is logged, then the app quits.
    foreach ([['call', 'demo_echo', "a\tb", '2'], ['call', 'demo_nope'], ['module', 'demo'], ['module', 'nope'], ['module-error', 'demo']] as $request) {
        $shell->send(...$request);
        $received('|');
    }
    $shell->send('quit');
    exit(0);
}
// S and V share the window component rk-screens (pages 0 and 1); F is rk-screens-L (Window subclass L).
$shell->send('open', match ($mode) { 'nope' => 'Nope', 'framed' => 'rk-screens-L', default => 'rk-screens' }, 'Fake', '300', '200');
$reply = $received(' ');
if ($mode === 'open-exit3') {
    $shell->send('close', $reply[1]);
    exit(3);
}
if ($mode === 'framed') {
    // A framed window's controls from PHP (Window::toggleMaximize(), minimize()).
    $shell->send('maximize', $reply[1], '1');
    $shell->send('minimize', $reply[1]);
    $shell->send('run');
    while ($received(' ') !== null) {
    }
    exit(0);
}
if ($mode === 'view') {
    $shell->send('set-int', $reply[1], 'rk-page', '1');
    $shell->send('set-list', $reply[1], 'p2', 'php', 'slint', 'desktop');
} else {
    $shell->send('set-string', $reply[1], 'p1', "tab\there");
}
$shell->send('run');
while ($received(' ') !== null) {
}
