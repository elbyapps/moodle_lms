<?php
// Lightweight boundary regression tests; no Moodle DB, credentials or network services.
// Run in a disposable PHP 8.2+ CLI container (see docs/download-offload.md).
namespace {
    define('MOODLE_INTERNAL', true);
    define('CLI_SCRIPT', true);
    define('AJAX_SCRIPT', false);
    define('OBJECT_LOCATION_DUPLICATED', 1);
    define('OBJECT_LOCATION_EXTERNAL', 2);
    $DB = new class {
        public function get_field($table, $field, $conditions) {
            return $GLOBALS['fs']->external ? OBJECT_LOCATION_DUPLICATED : 0;
        }
    };
    function check($condition, $message) {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
    // Isolate the file-system dependency boundary; execute the real vendored
    // ObjectFS class, not a reimplementation of its dispatch logic.
    class stored_file {
        public function get_contenthash() { return 'testhash'; }
        public function get_filesize() { return 100; }
    }
    class file_system_filedir {
        protected function get_local_path_from_hash($hash, $fetch = false) {
            check(!$fetch, 'Offload must never fetch an external-only object');
            return $GLOBALS['localpath'];
        }
        public function supports_xsendfile() { return $GLOBALS['offloadenabled']; }
        public function xsendfile($hash) { return false; }
        public function xsendfile_file(stored_file $file): bool { return false; }
    }
    function xsendfile($path) {
        $GLOBALS['offloadpaths'][] = $path;
        return $GLOBALS['offloadaccepted'];
    }
    $root = dirname(__DIR__, 2);
    $temp = sys_get_temp_dir() . '/download-offload-' . bin2hex(random_bytes(6));
    mkdir($temp, 0700);
    register_shutdown_function(function() use ($temp) {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temp,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temp);
    });
    foreach (['admin/tool/objectfs/lib.php', 'lib/filestorage/file_system.php',
            'lib/filestorage/file_system_filedir.php', 'lib/filestorage/file_storage.php', 'lib/xsendfilelib.php'] as $path) {
        if (!is_dir(dirname("$temp/$path"))) {
            mkdir(dirname("$temp/$path"), 0700, true);
        }
        file_put_contents("$temp/$path", '<?php // Dependency boundary stub.');
    }
    $CFG = (object)['dirroot' => $temp, 'libdir' => "$temp/lib"];
    require "$root/vendor/admin_tool_objectfs/classes/local/store/object_file_system.php";
}
namespace tool_objectfs\local\store {
    class offload_test_filesystem extends object_file_system {
        public $signed = false;
        public $ranges = [];
        public $external = false;
        public function __construct() {
            $this->externalclient = new class {
                public $proxied = false;
                public function support_presigned_urls() { return true; }
                public function proxy_range_request($file, $ranges) { $this->proxied = true; return true; }
            };
        }
        protected function initialise_external_client($config) {}
        public function is_configured() { return true; }
        public function presigned_url_configured() { return $this->signed; }
        public function presigned_url_should_redirect_file($file) { return true; }
        public function presigned_url_should_redirect($hash, $headers = []) { return true; }
        public function redirect_to_presigned_url($hash, $headers = []) { $GLOBALS['redirected'] = true; return true; }
        public function get_valid_http_ranges($filesize) { return $this->ranges; }
        public function prefer_external($value) {
            $property = new \ReflectionProperty(object_file_system::class, 'preferexternal');
            $property->setValue($this, $value);
        }
    }
}
namespace {
    $localpath = "$temp/content";
    file_put_contents($localpath, 'local file bytes');
    $offloadenabled = $offloadaccepted = true;
    $offloadpaths = [];
    $fs = new \tool_objectfs\local\store\offload_test_filesystem();
    $file = new stored_file();
    check($fs->xsendfile_file($file), 'Local file must offload with ObjectFS configured');
    check($offloadpaths === [$localpath], 'Use the local path, never a remote stream URI');
    check($fs->xsendfile('testhash'), 'Legacy hash API must also offload');
    $fs->ranges = [[0, 9]];
    $fs->external = true;
    check($fs->xsendfile_file($file) && !$fs->externalclient->proxied, 'Local ranges belong to nginx');
    $offloadpaths = [];
    $redirected = false;
    $fs->signed = true;
    check($fs->xsendfile_file($file) && $redirected && !$offloadpaths, 'Signed redirects retain priority');
    $redirected = false;
    check($fs->xsendfile('testhash') && $redirected, 'Legacy signed redirect retains priority');
    $fs->signed = false;
    $fs->ranges = [];
    $fs->prefer_external(true);
    check(!$fs->xsendfile_file($file), 'Respect preferexternal');
    $fs->prefer_external(false);
    $offloadenabled = false;
    check(!$fs->xsendfile_file($file), 'Disabled xsendfile must retain streaming');
    $offloadenabled = true;
    $offloadaccepted = false;
    check(!$fs->xsendfile_file($file), 'Alias/header rejection must retain streaming');
    $offloadaccepted = true;
    unlink($localpath);
    check(!$fs->xsendfile_file($file), 'External-only files must not get a local redirect');
    $fs->ranges = [[0, 9]];
    check($fs->xsendfile_file($file) && $fs->externalclient->proxied, 'Retain remote range fallback');

    require "$root/vendor/local_reblibrary/classes/download_delivery.php";
    foreach (['resources/abc/file.pdf', 'resources/abc/covers/cover.jpg', 'resources/abc/Unit 1.pdf',
            'resources/abc/Ikinyarwanda-é.pdf'] as $key) {
        check(\local_reblibrary\download_delivery::valid_key($key), "Valid key rejected: $key");
    }
    foreach (['', 'other/file.pdf', 'resources/', 'resources/../secret', 'resources/a/./b',
            'resources//a', "resources/a\r\nX-Test: bad", 'resources/a\\b'] as $key) {
        check(!\local_reblibrary\download_delivery::valid_key($key), 'Invalid key accepted');
    }
    check(\local_reblibrary\download_delivery::valid_url('https://storage.example.test/file?x=1&y=2'),
        'Accept raw HTTPS signature query');
    foreach (['http://storage.example.test/file', '/relative', 'https://user:pass@storage.example.test/file',
            'https://storage.example.test/file#fragment', "https://storage.example.test/file\r\nInjected: bad"] as $url) {
        check(!\local_reblibrary\download_delivery::valid_url($url), 'Reject unsafe download URL');
    }
    $headers = \local_reblibrary\download_delivery::response_headers('resources/a/Unit 1.pdf');
    check($headers['ResponseContentDisposition'] === "inline; filename*=UTF-8''Unit%201.pdf", 'Encode disposition filename');
    check($headers['ResponseCacheControl'] === 'private, max-age=600', 'Private cache policy');
    check(str_starts_with(\local_reblibrary\download_delivery::response_headers('resources/a/test.svg')
        ['ResponseContentDisposition'], 'attachment'), 'Active content is an attachment');
    echo "PASS: ObjectFS dispatch and library download policy\n";
}
