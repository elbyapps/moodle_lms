<?php
// Real Moodle X-Sendfile guard plus the actual startup instruction, no DB/network.
// Run as root in a disposable PHP/Moodle image, with the checkout mounted read-only.
define('MOODLE_INTERNAL', true);
$root = dirname(__DIR__, 3);
$lib = $argv[1] ?? '/var/www/html/moodle_app/public/lib';
require "$lib/xsendfilelib.php";
$temp = sys_get_temp_dir() . '/xsendfile-bootstrap-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
file_put_contents("$temp/file.pdf", 'test bytes');
$CFG = (object)['xsendfile' => 'X-Accel-Redirect', 'xsendfilealiases' => ['/dataroot/' => $temp],
    'localrequestdir' => "$temp/requestdir"];
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    check(!xsendfile("$temp/file.pdf"), 'Reproduce fresh-container guard failure in bundled Moodle');
    $entrypoint = file_get_contents("$root/docker/php/docker-entrypoint.sh");
    check(preg_match('~^install -d -o www-data -g www-data -m 0700 /tmp/requestdir$~m', $entrypoint, $match) === 1,
        'Startup must initialise the request directory before FPM');
    $command = str_replace('/tmp/requestdir', escapeshellarg($CFG->localrequestdir), $match[0]);
    exec($command, $output, $status);
    check($status === 0, 'Startup directory creation failed');
    clearstatcache();
    check(is_dir($CFG->localrequestdir) && (fileperms($CFG->localrequestdir) & 0777) === 0700,
        'Request base must exist with private permissions');
    check(xsendfile("$temp/file.pdf"), 'Normal local file must offload after startup preparation');
    file_put_contents("$temp/requestdir/temporary.pdf", 'temporary bytes');
    check(!xsendfile("$temp/requestdir/temporary.pdf"), 'Request-local files must still be excluded');
    echo "PASS: real Moodle fresh-container X-Sendfile guard; startup fix; temporary-file exclusion\n";
} finally {
    if (is_file("$temp/requestdir/temporary.pdf")) { unlink("$temp/requestdir/temporary.pdf"); }
    if (is_dir("$temp/requestdir")) { rmdir("$temp/requestdir"); }
    unlink("$temp/file.pdf");
    rmdir($temp);
}
