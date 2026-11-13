<?php

// Minimal HTTP receiver for the webhook tests (php -S 0.0.0.0:8080 webhook-receiver.php):
// every request is recorded; GET /_requests returns them as JSON, DELETE /_requests clears them.
$store = sys_get_temp_dir().'/symine-webhook-requests.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ('/_requests' === $path) {
    if ('DELETE' === $_SERVER['REQUEST_METHOD']) {
        @unlink($store);
        echo '[]';

        return;
    }
    $lines = is_file($store) ? file($store, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    echo '['.implode(',', $lines).']';

    return;
}
$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower($k)] = $v;
}
$entry = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'headers' => $headers, 'body' => file_get_contents('php://input')];
file_put_contents($store, json_encode($entry, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
echo '{"ok":true}';
