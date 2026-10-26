<?php

// php -S router for MultipartTest: answers with what PHP itself parsed from a multipart body, as
// JSON: the fields, and per file its name, type, size and sha256.
header('Content-Type: application/json');
$files = [];
foreach ($_FILES as $field => $file) {
    foreach ((array) $file['name'] as $i => $name) {
        $tmp = is_array($file['tmp_name']) ? $file['tmp_name'][$i] : $file['tmp_name'];
        $files[] = [
            'field' => $field,
            'name' => $name,
            'type' => is_array($file['type']) ? $file['type'][$i] : $file['type'],
            'error' => is_array($file['error']) ? $file['error'][$i] : $file['error'],
            'sha256' => hash_file('sha256', $tmp),
        ];
    }
}
echo json_encode(['contentType' => $_SERVER['CONTENT_TYPE'] ?? '', 'fields' => $_POST, 'files' => $files]);
