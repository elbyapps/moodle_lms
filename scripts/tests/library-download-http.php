<?php
// Exercise the real controller over HTTP against stubbed Moodle/S3 boundaries.
// The listener is inside a --network none disposable container, never published.
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/library-http-' . bin2hex(random_bytes(6));
mkdir("$temp/local/reblibrary", 0700, true);
copy("$root/vendor/local_reblibrary/download.php", "$temp/local/reblibrary/download.php");
copy("$root/scripts/tests/fixtures/library-config.php", "$temp/config.php");
file_put_contents("$temp/router.php", '<?php chdir(__DIR__ . "/local/reblibrary"); try { require __DIR__ . "/local/reblibrary/download.php"; }' .
    ' catch (Throwable $e) { http_response_code(400); echo "rejected"; }');
putenv("TEST_REPO=$root");
$log = fopen("$temp/server.log", 'w');
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:18973', "$temp/router.php"],
    [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, $temp);
if (!is_resource($process)) {
    throw new RuntimeException('Could not launch test HTTP server');
}
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', 18973, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('Test HTTP server not ready'); }
    $cases = [
        ['GET', 'resources/a/Unit 1.pdf', '', '', 302],
        ['GET', 'resources/a/Unit 1.pdf', '', 'Range: bytes=0-1023', 302],
        ['HEAD', 'resources/a/cover.jpg', '', '', 302],
        ['GET', 'resources/a/covers/small.jpg', '', '', 302],
        ['GET', 'resources/a/audio.mp3', '', '', 302],
        ['GET', 'resources/a/archive.zip', '', '', 302],
        ['GET', 'resources/a/video.mp4', '', 'Range: bytes=1024-', 302],
        ['GET', 'resources/a/missing.pdf', '', '', 302], // Storage, not PHP, resolves missing objects.
        ['GET', 'resources/a/file.pdf', '', 'Range: bytes=999999999-', 302], // Storage handles 416.
        ['GET', 'resources/a/file.pdf', 'anonymous', '', 303],
        ['GET', 'resources/a/file.pdf', 'denied', '', 403],
        ['GET', 'resources/../secret', '', '', 400],
        ['GET', "resources/a\r\nInjected: bad", '', '', 400],
        ['GET', '', '', '', 400],
        ['POST', 'resources/a/file.pdf', '', '', 405],
        ['GET', 'resources/a/file.pdf', 'signing-failure', '', 503],
        ['HEAD', 'resources/a/file.pdf', 'signing-failure', '', 503],
        ['GET', 'resources/a/file.pdf', 'plaintext', '', 503],
    ];
    foreach ($cases as [$method, $key, $mode, $range, $expected]) {
        $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 3,
            'follow_location' => 0, 'ignore_errors' => true, 'header' => $range]]);
        $body = file_get_contents('http://127.0.0.1:18973/?' . http_build_query(['key' => $key, 'mode' => $mode]),
            false, $context);
        $headers = $http_response_header;
        if (!str_contains($headers[0], " $expected ")) {
            throw new RuntimeException("Unexpected status for $method/$mode: " . $headers[0] .
                " body=" . $body . " log=" . file_get_contents("$temp/server.log"));
        }
        $text = implode("\n", $headers);
        if ($expected === 302 || $expected === 503) {
            foreach (['Cache-Control: private, no-store', 'Referrer-Policy: no-referrer'] as $header) {
                if (!str_contains($text, $header)) { throw new RuntimeException("Missing header: $header"); }
            }
        }
        if ($expected === 302) {
            $location = 'Location: https://storage.example.test/bucket/' . rawurlencode($key) . '?signed=test';
            if (!str_contains($text, $location)) { throw new RuntimeException('Wrong storage redirect'); }
            if ($body !== '') { throw new RuntimeException('Redirect must not stream bytes or an HTML page'); }
        } else if (str_contains($text, 'signed=')) {
            throw new RuntimeException('Unauthorised, invalid or failed request received a signed URL');
        }
        if ($expected === 503) {
            if (str_contains($text, 'Location:') || $body !== ($method === 'HEAD' ? '' : 'Download unavailable')) {
                throw new RuntimeException('Signing failure must fail closed, never proxy or expose SDK errors');
            }
        }
    }
    if (str_contains(file_get_contents("$temp/server.log"), 'SECRET')) {
        throw new RuntimeException('Signing details leaked into logs');
    }
    echo "PASS: all library assets redirect; GET/HEAD, Range, auth, no-store, HTTPS and failure without proxy\n";
} finally {
    proc_terminate($process);
    fclose($pipes[0]);
    proc_close($process);
    fclose($log);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temp);
}
