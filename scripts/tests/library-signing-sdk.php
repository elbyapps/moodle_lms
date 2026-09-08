<?php
// Offline serialization/signing with Moodle's real bundled AWS SDK and dummy credentials.
// No Moodle database bootstrap or network access. Optional argv[1]: Moodle public/lib path.
$root = dirname(__DIR__, 2);
$lib = $argv[1] ?? '/var/www/html/moodle_app/public/lib';
if (!is_file("$lib/aws-sdk/src/S3/S3Client.php")) {
    throw new RuntimeException('Provide the Moodle public/lib directory containing the AWS SDK');
}
$namespaces = [
    'Aws\\' => 'aws-sdk/src',
    'GuzzleHttp\\Promise\\' => 'guzzlehttp/promises/src',
    'GuzzleHttp\\Psr7\\' => 'guzzlehttp/psr7/src',
    'GuzzleHttp\\' => 'guzzlehttp/guzzle/src',
    'Psr\\Http\\Message\\' => 'psr/http-message/src',
    'Psr\\Http\\Client\\' => 'psr/http-client/src',
    'JmesPath\\' => 'jmespath/src',
];
spl_autoload_register(static function($class) use ($lib, $namespaces) {
    foreach ($namespaces as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = "$lib/$directory/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
});
foreach (['aws-sdk/src/functions.php', 'guzzlehttp/guzzle/src/functions_include.php',
        'ralouphie/getallheaders/src/getallheaders.php', 'symfony/deprecation-contracts/function.php'] as $file) {
    require_once "$lib/$file";
}
define('MOODLE_INTERNAL', true);
class moodle_exception extends RuntimeException {
    public function __construct($code, $component = '', $link = '', $data = null, $debug = '') {
        parent::__construct($code . ': ' . $debug);
    }
}
function get_config($component, $name) {
    return ['s3_endpoint' => 'https://internal.example.test', 's3_public_endpoint' => 'https://storage.example.test',
        's3_access_key' => 'test', 's3_secret_key' => 'test', 's3_bucket' => 'library', 's3_region' => 'us-east-1'][$name];
}
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
$temp = sys_get_temp_dir() . '/signing-sdk-' . bin2hex(random_bytes(6));
mkdir("$temp/lib", 0700, true);
file_put_contents("$temp/lib/filelib.php", '<?php // Moodle boundary stub.');
$CFG = (object)['dirroot' => $temp];
try {
    require "$root/vendor/local_reblibrary/classes/s3_client.php";
    $client = new \local_reblibrary\s3_client();
    $signatures = [];
    foreach (['GET', 'HEAD'] as $method) {
        $url = $client->get_presigned_get_url('resources/a/Unit 1.pdf', 600, [
            'Bucket' => 'wrong', 'Key' => 'wrong',
            'ResponseCacheControl' => 'private, max-age=600', 'ResponseContentDisposition' => 'inline',
        ], $method);
        $parts = parse_url($url);
        parse_str($parts['query'], $query);
        check($parts['host'] === 'storage.example.test' && $parts['path'] === '/library/resources/a/Unit%201.pdf',
            'Wrong public endpoint or object');
        check(($query['X-Amz-Expires'] ?? '') === '600' && !empty($query['X-Amz-Signature']), 'Missing short signature');
        check(isset($query['response-cache-control']) === ($method === 'GET'), 'GET-only cache override');
        check(isset($query['response-content-disposition']) === ($method === 'GET'), 'GET-only disposition override');
        if ($method === 'GET') {
            check($query['response-cache-control'] === 'private, max-age=600', 'Wrong cache override');
        }
        $signatures[$method] = $query['X-Amz-Signature'];
    }
    check($signatures['GET'] !== $signatures['HEAD'], 'HEAD needs its own signature');
    echo "PASS: real bundled AWS SDK serializes/signs GET and HEAD without network access\n";
} finally {
    unlink("$temp/lib/filelib.php");
    rmdir("$temp/lib");
    rmdir($temp);
}
