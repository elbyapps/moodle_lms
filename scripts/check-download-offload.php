<?php
// Read-only production preflight. Reads at most 1 KiB per signed GET, never
// prints bearer URLs/credentials, and does not change ObjectFS or plugin settings.
// Usage: php check-download-offload.php --contextid=123 [--public-ip=203.0.113.1]
// Optional: --library-key=resources/.../file.pdf (also requires matching storage CORS).
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle_app/config.php');

$options = getopt('', ['contextid:', 'public-ip:', 'library-key:']);
$contextid = (int)($options['contextid'] ?? 0);
$publicip = $options['public-ip'] ?? '';
if (!$contextid || ($publicip !== '' && !filter_var($publicip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
    fwrite(STDERR, "Usage: --contextid=<folder-context-id> [--public-ip=<public IPv4>] [--library-key=resources/.../file.pdf]\n");
    exit(2);
}

/** Validate a short signed GET without following redirects or logging the signature. */
function check_signed_range(string $label, string $url, string $publicip, string $origin, ?string $expected = null,
        bool $requirecors = false): bool {
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        echo "$label FAIL: browser endpoint must be HTTPS\n";
        return false;
    }
    $body = '';
    $headers = [];
    $curl = curl_init($url);
    $settings = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Range: bytes=0-1023', 'Origin: ' . $origin],
        CURLOPT_WRITEFUNCTION => static function($curl, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 1024) { return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function($curl, $line) use (&$headers) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
            return strlen($line);
        },
    ];
    if ($publicip !== '') {
        $settings[CURLOPT_RESOLVE] = [$parts['host'] . ':' . ($parts['port'] ?? 443) . ':' . $publicip];
    }
    curl_setopt_array($curl, $settings);
    $success = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $seconds = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
    $errno = curl_errno($curl);
    curl_close($curl);
    $alloworigin = $headers['access-control-allow-origin'] ?? '';
    $cors = $alloworigin === $origin || $alloworigin === '*';
    $exposed = array_map('trim', explode(',', strtolower($headers['access-control-expose-headers'] ?? '')));
    $rangecors = in_array('*', $exposed, true) ||
        !array_diff(['accept-ranges', 'content-range', 'etag'], $exposed);
    $matches = $expected === null || hash_equals($expected, $body);
    $validrange = preg_match('/^bytes 0-1023\/[0-9]+$/', $headers['content-range'] ?? '') === 1;
    $ok = $success !== false && $code === 206 && strlen($body) === 1024 && $validrange && $matches &&
        (!$requirecors || ($cors && $rangecors));
    printf("%s %s host=%s status=%d bytes=%d seconds=%.3f curl_errno=%d range=%s local_match=%s cors=%s exposed_range=%s\n",
        $label, $ok ? 'PASS' : 'FAIL', $parts['host'], $code, strlen($body), $seconds, $errno,
        $validrange ? 'yes' : 'no', $expected === null ? 'not-tested' : ($matches ? 'yes' : 'no'),
        $cors ? 'yes' : 'no', $rangecors ? 'yes' : 'no');
    if (!$ok && preg_match('~<Code>([A-Za-z0-9_]+)</Code>~', $body, $errorcode)) {
        // The protocol error code is useful; Message/RequestId may contain secrets.
        echo $label . ' storage_error=' . $errorcode[1] . "\n";
    }
    return $ok;
}

try {
    // Context-indexed, bounded lookup: no whole-site file scans.
    $records = $DB->get_records_sql('SELECT id, contenthash, filesize FROM {files}
        WHERE contextid = :contextid AND component = :component AND filearea = :filearea
            AND filesize >= 1048576 ORDER BY id',
        ['contextid' => $contextid, 'component' => 'mod_folder', 'filearea' => 'content'], 0, 1);
    $record = reset($records);
    if (!$record) {
        throw new RuntimeException('No >=1 MiB folder file found in the supplied context');
    }
    $hash = $record->contenthash;
    $localpath = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
    $expected = null;
    if (is_readable($localpath)) {
        $handle = fopen($localpath, 'rb');
        $expected = fread($handle, 1024);
        fclose($handle);
    }
    printf("sample_file_id=%d local_copy=%s folder_max_mib=%s presigned=%s preferexternal=%s\n", $record->id,
        $expected === null ? 'no' : 'yes', get_config('folder', 'maxsizetodownload'),
        get_config('tool_objectfs', 'enablepresignedurls'), get_config('tool_objectfs', 'preferexternal'));
    $originparts = parse_url($CFG->wwwroot);
    $origin = $originparts['scheme'] . '://' . $originparts['host'] .
        (isset($originparts['port']) ? ':' . $originparts['port'] : '');
    $filesystem = get_file_storage()->get_file_system();
    $signed = $filesystem->get_external_client()->generate_presigned_url($hash);
    // moodle_url::__toString() HTML-escapes '&' into '&amp;'. An HTTP client
    // needs the unescaped query string or the signature parameters are invalid.
    $ok = check_signed_range('ObjectFS', $signed->url->out(false), $publicip, $origin, $expected);
    if (isset($options['library-key'])) {
        $key = $options['library-key'];
        if (!str_starts_with($key, 'resources/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f\x7f]~', $key)) {
            throw new RuntimeException('Invalid library key');
        }
        $client = new \local_reblibrary\s3_client();
        $url = $client->get_presigned_get_url($key, 600);
        $ok = check_signed_range('Library', $url, $publicip, $origin, null, true) && $ok;
    }
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    // SDK messages may contain URLs with signatures; do not print them.
    fwrite(STDERR, 'Preflight failed (' . get_class($error) . "). No settings changed.\n");
    exit(1);
}
