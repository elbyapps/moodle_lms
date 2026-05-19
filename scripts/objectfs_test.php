<?php
// scripts/objectfs_test.php
//
// Round-trip test helper for tool_objectfs. Invoked from inside the PHP
// container by scripts/test-s3-roundtrip.sh. Three subcommands:
//
//   seed <path>          create a Moodle file from <path>, print JSON
//   readback <pnhash>    re-read that file via the file API, print its SHA1
//   cleanup  <pnhash>    delete the file from Moodle
//
// "pnhash" is the pathname hash that 'seed' prints. We round-trip via that
// instead of a file ID so the helper has no DB-schema assumptions.

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle_app/public/config.php');

$action = $argv[1] ?? '';

switch ($action) {
    case 'seed':
        $sourcepath = $argv[2] ?? '';
        if (!is_file($sourcepath)) {
            fwrite(STDERR, "Source file not found: $sourcepath\n");
            exit(1);
        }
        $content = file_get_contents($sourcepath);
        if ($content === false) {
            fwrite(STDERR, "Could not read $sourcepath\n");
            exit(1);
        }
        $sha1 = sha1($content);

        $fs = get_file_storage();
        $filerecord = (object)[
            'contextid' => context_system::instance()->id,
            'component' => 'core',
            'filearea'  => 'unused',
            'itemid'    => 0,
            'filepath'  => '/objectfs-test/',
            'filename'  => 'payload-' . substr($sha1, 0, 12) . '.bin',
        ];
        $existing = $fs->get_file(
            $filerecord->contextid, $filerecord->component,
            $filerecord->filearea, $filerecord->itemid,
            $filerecord->filepath, $filerecord->filename
        );
        if (!$existing) {
            $existing = $fs->create_file_from_string($filerecord, $content);
        }
        if (!$existing) {
            fwrite(STDERR, "create_file_from_string failed\n");
            exit(1);
        }
        $contenthash = $existing->get_contenthash();
        $localpath = $CFG->dataroot . '/filedir/'
            . substr($contenthash, 0, 2) . '/'
            . substr($contenthash, 2, 2) . '/'
            . $contenthash;
        echo json_encode([
            'sha1'         => $sha1,
            'contenthash'  => $contenthash,
            'pathnamehash' => $existing->get_pathnamehash(),
            'filesize'     => (int)$existing->get_filesize(),
            'localpath'    => $localpath,
        ]) . "\n";
        exit(0);

    case 'readback':
        $pnhash = $argv[2] ?? '';
        if ($pnhash === '') {
            fwrite(STDERR, "Missing pathnamehash\n");
            exit(2);
        }
        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($pnhash);
        if (!$file) {
            fwrite(STDERR, "File not found by pathnamehash: $pnhash\n");
            exit(1);
        }
        $content = $file->get_content();
        if ($content === false || $content === null) {
            fwrite(STDERR, "get_content() returned empty for $pnhash\n");
            exit(1);
        }
        echo sha1($content) . "\n";
        exit(0);

    case 'cleanup':
        $pnhash = $argv[2] ?? '';
        if ($pnhash === '') {
            fwrite(STDERR, "Missing pathnamehash\n");
            exit(2);
        }
        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($pnhash);
        if ($file) {
            $file->delete();
            echo "deleted\n";
        } else {
            echo "not-found\n";
        }
        exit(0);

    default:
        fwrite(STDERR, "Usage: objectfs_test.php {seed <path>|readback <pnhash>|cleanup <pnhash>}\n");
        exit(2);
}
