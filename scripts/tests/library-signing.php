<?php
// SDK boundary contract: public endpoint, HTTP method, fixed key/bucket and response overrides.
namespace Aws\S3 {
    class S3Client {
        public function __construct($config) { $GLOBALS['sdkconfig'] = $config; }
        public function getCommand($operation, $arguments) {
            $GLOBALS['command'] = [$operation, $arguments];
            return $GLOBALS['command'];
        }
        public function createPresignedRequest($command, $expiration) {
            $GLOBALS['expiration'] = $expiration;
            return new class { public function getUri() { return 'https://storage.example.test/signed'; } };
        }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    function get_config($component, $name) {
        return ['s3_endpoint' => 'https://internal.example.test', 's3_public_endpoint' => 'https://storage.example.test',
            's3_access_key' => 'test', 's3_secret_key' => 'test', 's3_bucket' => 'library', 's3_region' => 'test-region'][$name];
    }
    function check($condition, $message) {
        if (!$condition) { throw new RuntimeException($message); }
    }
    $root = dirname(__DIR__, 2);
    $temp = sys_get_temp_dir() . '/signing-' . bin2hex(random_bytes(6));
    mkdir("$temp/lib", 0700, true);
    file_put_contents("$temp/lib/filelib.php", '<?php // Moodle boundary stub.');
    $CFG = (object)['dirroot' => $temp];
    try {
        require "$root/vendor/local_reblibrary/classes/s3_client.php";
        $client = new \local_reblibrary\s3_client();
        check($sdkconfig['endpoint'] === 'https://internal.example.test', 'Server uses internal endpoint');
        foreach (['GET' => 'GetObject', 'HEAD' => 'HeadObject'] as $method => $operation) {
            $url = $client->get_presigned_get_url('resources/a/file.pdf', 600, [
                'Bucket' => 'wrong', 'Key' => 'wrong', 'ACL' => 'public-read',
                'ResponseCacheControl' => 'private, max-age=600',
                'ResponseContentDisposition' => 'inline',
            ], $method);
            check($sdkconfig['endpoint'] === 'https://storage.example.test', 'Sign against browser endpoint');
            check($sdkconfig['use_path_style_endpoint'] === true, 'Garage requires path-style URLs');
            $expected = ['Bucket' => 'library', 'Key' => 'resources/a/file.pdf'];
            if ($method === 'GET') {
                $expected += ['ResponseCacheControl' => 'private, max-age=600', 'ResponseContentDisposition' => 'inline'];
            }
            check($command === [$operation, $expected],
                'Sign exact method/headers; HEAD has no response overrides and bucket/key remain fixed');
            check($expiration === '+600 seconds', 'Short-lived signature');
        }
        $client->get_presigned_get_url('resources/a/file.pdf');
        check($command === ['GetObject', ['Bucket' => 'library', 'Key' => 'resources/a/file.pdf']],
            'Existing callers remain compatible');
        try {
            $client->get_presigned_get_url('resources/a/file.pdf', 600, [], 'DELETE');
            throw new RuntimeException('Invalid HTTP method accepted');
        } catch (InvalidArgumentException $expected) {
        }
        echo "PASS: library signing SDK contract (GET/HEAD, public endpoint, expiry, overrides)\n";
    } finally {
        unlink("$temp/lib/filelib.php");
        rmdir("$temp/lib");
        rmdir($temp);
    }
}
