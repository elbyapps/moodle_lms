<?php
// Minimal Moodle/S3 boundaries for exercising the actual redirect-only controller.
namespace core\session {
    class manager {
        public static function write_close() { $GLOBALS['sessionclosed'] = true; }
    }
}
namespace local_reblibrary {
    class s3_client {
        public function __construct() {
            if (empty($GLOBALS['sessionclosed'])) { throw new \RuntimeException('Session still locked'); }
        }
        public function get_presigned_get_url($key, $expiration, array $headers, $method) {
            if ($method !== $_SERVER['REQUEST_METHOD'] || $expiration !== 600 ||
                    $headers['ResponseCacheControl'] !== 'private, max-age=600') {
                throw new \RuntimeException('Invalid download policy');
            }
            if (($_GET['mode'] ?? '') === 'signing-failure') {
                throw new \RuntimeException('SECRET-SIGNING-DETAIL https://storage.example.test/?signed=SECRET');
            }
            if (($_GET['mode'] ?? '') === 'plaintext') {
                return 'http://storage.example.test/?signed=test';
            }
            return 'https://storage.example.test/bucket/' . rawurlencode($key) . '?signed=test';
        }
        public function __call($method, $args) {
            throw new \RuntimeException('Download must not inspect or stream objects: ' . $method);
        }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    define('PARAM_TEXT', 0);
    require getenv('TEST_REPO') . '/vendor/local_reblibrary/classes/download_delivery.php';
    class moodle_exception extends \RuntimeException {
        public function __construct($errorcode, $component = '') { parent::__construct($errorcode); }
    }
    class context_system { public static function instance() { return new self(); } }
    function require_login() {
        if (($_GET['mode'] ?? '') === 'anonymous') {
            header('Location: /login/index.php', true, 303);
            exit;
        }
    }
    function require_capability($capability, $context) {
        if ($capability !== 'local/reblibrary:view' || ($_GET['mode'] ?? '') === 'denied') {
            http_response_code(403);
            exit;
        }
    }
    function required_param($name, $type) { return $_GET[$name] ?? ''; }
    // A missing/disabled legacy flag must not bring back the PHP proxy.
    function get_config($component, $name) { return false; }
    function get_string($key, $component) { return 'Download unavailable'; }
}
