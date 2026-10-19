<?php

// The test server for HttpLibraryTest (php -S 127.0.0.1:<port> router.php).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/hello':
        header('Content-Type: text/plain');
        header('X-Test: one');
        echo 'Hello, world';
        break;
    case '/binary':
        header('Content-Type: application/octet-stream');
        echo "a\0b\nc\td\\e";
        break;
    case '/missing':
        http_response_code(404);
        echo 'not here';
        break;
    case '/redirect':
        header('Location: /hello', true, 302);
        echo 'moved';
        break;
    case '/echo':
        header('Content-Type: text/plain');
        echo $_SERVER['REQUEST_METHOD'], "\n", $_SERVER['HTTP_X_ASK'] ?? '-', "\n", file_get_contents('php://input');
        break;
    case '/slow':
        sleep(5);
        echo 'late';
        break;
    case '/big':
        header('Content-Length: ' . (2 * 1024 * 1024));
        for ($i = 0; $i < 32; $i++) {
            echo str_repeat(chr(65 + $i % 26), 65536);
            flush();
            usleep(20000);
        }
        break;
    default:
        http_response_code(500);
        echo "unknown path $path";
        break;
}
