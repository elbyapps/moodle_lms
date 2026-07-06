<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * ELMS Sync v2 step 7 (part 3) integration spike: content-addressed file channel.
 *
 * Proves the submission-blob transfer channel (doc §9.1):
 *   1. The central upload_file endpoint stores a blob content-addressed, dedups an
 *      already-held contenthash (idempotent), and rejects bytes that don't match the
 *      claimed hash.
 *   2. The school ship_files task uploads each pending local_syncqueue_files blob and
 *      marks it synced only on confirmed receipt; a blob whose local source is gone is
 *      marked 'missing' (reported, not retried forever).
 *
 * The ship task runs against a test-double client that calls the real endpoint locally
 * (no HTTP). Restores every touched row/config/file.
 *
 * Usage:  php integration_file_channel.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\external\upload_file;
use local_syncqueue\school_manager;
use local_syncqueue\sync_client;
use local_syncqueue\task\ship_files;

const FC_SCHOOL = 'itestfcschool';

/** Test-double client: call the real endpoint in central mode (no HTTP). */
class itest_fc_client extends sync_client {
    public string $sid = '';
    public string $key = '';
    public function upload_syncfile(string $contenthash, string $filename, string $contentb64): array {
        $prev = get_config('local_syncqueue', 'mode');
        set_config('mode', 'central', 'local_syncqueue');
        try {
            return upload_file::execute($this->sid, $this->key, $contenthash, $filename, $contentb64);
        } finally {
            set_config('mode', $prev !== false ? $prev : 'school', 'local_syncqueue');
        }
    }
}

/** Test-double task: inject the double client. */
class itest_fc_task extends ship_files {
    public sync_client $doubleclient;
    protected function client(): sync_client {
        return $this->doubleclient;
    }
}

$fcfailures = [];
function fc_check(string $name, bool $ok, string $detail = ''): void {
    global $fcfailures;
    if (!$ok) {
        $fcfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

global $DB, $CFG;
$syscontext = \context_system::instance();
$fs = get_file_storage();

$confignames = ['mode', 'enabled', 'push_v2', 'schoolid', 'apikey', 'centralserver', 'wstoken'];
$saved = [];
foreach ($confignames as $n) {
    $saved[$n] = get_config('local_syncqueue', $n);
}

/** Delete any stored blob for a contenthash in a syncqueue filearea. */
function fc_purge_area(\file_storage $fs, int $ctxid, string $area): void {
    foreach ($fs->get_area_files($ctxid, 'local_syncqueue', $area, false, 'id', false) as $f) {
        $f->delete();
    }
}

$fatal = null;
try {
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => FC_SCHOOL])) {
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => FC_SCHOOL]);
    }
    fc_purge_area($fs, $syscontext->id, upload_file::FILEAREA);
    fc_purge_area($fs, $syscontext->id, 'itestfcsource');
    $DB->delete_records('local_syncqueue_files', ['schoolid' => FC_SCHOOL]);

    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    $sm = new school_manager();
    $apikey = $sm->register_school(FC_SCHOOL, 'ITEST File Channel Fixture');

    // -----------------------------------------------------------------------
    // Phase 1 — the central receive endpoint.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: upload_file endpoint ---');
    $contentA = 'hello sync files A ' . str_repeat('x', 32);
    $hashA = sha1($contentA);
    $r1 = upload_file::execute(FC_SCHOOL, $apikey, $hashA, 'a.txt', base64_encode($contentA));
    fc_check('endpoint_stores',
        !empty($r1['received']) && empty($r1['dedup'])
            && $fs->get_file($syscontext->id, 'local_syncqueue', upload_file::FILEAREA, 0, '/', $hashA),
        'a fresh blob is received and stored content-addressed');

    $r2 = upload_file::execute(FC_SCHOOL, $apikey, $hashA, 'a.txt', base64_encode($contentA));
    fc_check('endpoint_dedups', !empty($r2['received']) && !empty($r2['dedup']),
        're-uploading the same contenthash confirms without re-storing (idempotent)');

    $r3 = upload_file::execute(FC_SCHOOL, $apikey, sha1('a different thing'), 'a.txt', base64_encode($contentA));
    fc_check('endpoint_rejects_mismatch', empty($r3['received']) && !empty($r3['error']),
        'bytes that do not match the claimed contenthash are rejected');

    // -----------------------------------------------------------------------
    // Phase 2 — the school ship task (double client -> real endpoint).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: ship_files task ---');
    set_config('mode', 'school', 'local_syncqueue');
    set_config('push_v2', 1, 'local_syncqueue');
    set_config('schoolid', FC_SCHOOL, 'local_syncqueue');
    set_config('centralserver', 'https://localhost', 'local_syncqueue');

    // A real local source blob + its pending file row.
    $contentB = 'hello sync files B ' . str_repeat('y', 48);
    $storedB = $fs->create_file_from_string([
        'contextid' => $syscontext->id, 'component' => 'local_syncqueue', 'filearea' => 'itestfcsource',
        'itemid' => 0, 'filepath' => '/', 'filename' => 'b.txt',
    ], $contentB);
    $hashB = $storedB->get_contenthash();
    $rowB = $DB->insert_record('local_syncqueue_files', (object) [
        'queueitemid' => null, 'schoolid' => FC_SCHOOL, 'contenthash' => $hashB, 'filename' => 'b.txt',
        'filesize' => strlen($contentB), 'mimetype' => 'text/plain', 'status' => 'pending', 'timecreated' => time(),
    ]);

    $task = new itest_fc_task();
    $client = new itest_fc_client();
    $client->sid = FC_SCHOOL;
    $client->key = $apikey;
    $task->doubleclient = $client;
    $task->execute();

    $rowBnow = $DB->get_record('local_syncqueue_files', ['id' => $rowB]);
    fc_check('ship_marks_synced',
        $rowBnow && $rowBnow->status === 'synced' && (int) $rowBnow->timesynced > 0,
        'the pending blob is marked synced with a timesynced');
    fc_check('ship_lands_centrally',
        (bool) $fs->get_file($syscontext->id, 'local_syncqueue', upload_file::FILEAREA, 0, '/', $hashB),
        'the shipped blob is now held centrally, content-addressed');

    // -----------------------------------------------------------------------
    // Phase 3 — a pending blob whose local source is gone -> 'missing'.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: missing local blob ---');
    $rowM = $DB->insert_record('local_syncqueue_files', (object) [
        'queueitemid' => null, 'schoolid' => FC_SCHOOL, 'contenthash' => sha1('never stored ' . uniqid('', true)),
        'filename' => 'gone.txt', 'filesize' => 10, 'mimetype' => 'text/plain', 'status' => 'pending',
        'timecreated' => time(),
    ]);
    $task->execute();
    fc_check('missing_source_flagged',
        $DB->get_field('local_syncqueue_files', 'status', ['id' => $rowM]) === ship_files::STATUS_MISSING,
        'a pending blob with no local source is marked missing, not retried forever');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $fcfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
fc_purge_area($fs, $syscontext->id, upload_file::FILEAREA);
fc_purge_area($fs, $syscontext->id, 'itestfcsource');
$DB->delete_records('local_syncqueue_files', ['schoolid' => FC_SCHOOL]);
$DB->delete_records('local_syncqueue_schools', ['schoolid' => FC_SCHOOL]);
foreach ($confignames as $n) {
    if ($saved[$n] === false) {
        unset_config($n, 'local_syncqueue');
    } else {
        set_config($n, $saved[$n], 'local_syncqueue');
    }
}
$residue = $DB->count_records('local_syncqueue_files', ['schoolid' => FC_SCHOOL])
    + $DB->count_records('local_syncqueue_schools', ['schoolid' => FC_SCHOOL]);
fc_check('cleanup_restored', $residue === 0,
    $residue === 0 ? 'fixture school, file rows, stored blobs and configs removed' : "residue={$residue}");

if (empty($fcfailures)) {
    cli_writeln('SPIKE RESULT: PASS - content-addressed file channel verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($fcfailures)));
exit(1);
