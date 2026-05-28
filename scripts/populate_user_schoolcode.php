<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backfill mdl_user.schoolcode + mdl_user.institution from SDMS, for the
 * students listed in an XLSX file.
 *
 * For each row in the workbook we extract a studentCode, call
 *   GET {sdms-url}/student?studentCode=<code>
 * pull `schoolCode` from the response, resolve the school name from the
 * local {elby_schools} cache (or fall back to the schoolCode itself), and
 * write both onto the matching Moodle user.
 *
 * Intended to be invoked via the repo Makefile:
 *   make populate-user-schoolcode             # write to DB
 *   make populate-user-schoolcode-dry         # preview, no writes
 *
 * Direct CLI invocation inside the php container also works:
 *   php /var/www/html/scripts/populate_user_schoolcode.php \
 *       --file=/data/PRISM\ SDMS\ List_rev.xlsx --dry-run -v
 *
 * @package   local_elby_dashboard
 * @copyright 2026 Rwanda TVET Board
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

// scripts/ is baked into the image at /var/www/html/scripts/. Moodle lives
// at /var/www/html/moodle_app/public/ — see Makefile clean-cache target.
require(__DIR__ . '/../moodle_app/public/config.php');
require_once($CFG->libdir . '/clilib.php');

// Reading XLSX: rather than depend on PhpSpreadsheet (not consistently
// bundled across Moodle 4.x/5.x), we parse the workbook ourselves below
// using ZipArchive + SimpleXML — both ship with every Moodle PHP build.
if (!class_exists('ZipArchive')) {
    cli_error('PHP ext-zip is required to read XLSX files.');
}
if (!function_exists('simplexml_load_string')) {
    cli_error('PHP ext-simplexml is required to read XLSX files.');
}

[$options, $unrecognized] = cli_get_params(
    [
        'help'         => false,
        'file'         => null,
        'sdms-url'     => getenv('SDMS_URL') ?: 'http://100.87.223.50:8082/sdms/api',
        'code-column'  => null,         // header name; auto-detected when null
        'sheet'        => null,         // sheet name or 0-based index
        'match'        => 'username',   // username | idnumber | email
                                       // (this workbook's "ID number" col
                                       //  is the Moodle username)
        'dry-run'      => false,
        'limit'        => 0,
        'sleep-ms'     => 0,
        'verbose'      => false,
    ],
    ['h' => 'help', 'v' => 'verbose']
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help'] || empty($options['file'])) {
    $help = <<<EOT
Populate mdl_user.schoolcode and mdl_user.institution from SDMS, for
students listed in an XLSX.

Required:
  --file=PATH         XLSX path (inside the container).

Options:
  --sdms-url=URL      SDMS API base. Default: env SDMS_URL or
                      http://100.87.223.50:8082/sdms/api
  --code-column=NAME  Header of the student-code column. When omitted, the
                      first header containing "student" plus "code" or
                      "number" is used.
  --sheet=NAME|N      Sheet to read (name, or 0-based index). When omitted,
                      every sheet in the workbook is processed; the code
                      column is auto-detected per sheet (sheets without a
                      recognisable header are skipped with a warning).
  --match=FIELD       Moodle user field to identify users by:
                      username (default), idnumber, or email.
  --dry-run           Print planned writes; do not modify the DB.
  --limit=N           Process at most N non-empty rows (0 = all).
  --sleep-ms=N        Sleep this many ms between SDMS calls.
  -v, --verbose       Per-row logging.
  -h, --help          This message.

EOT;
    cli_writeln($help);
    exit(empty($options['file']) ? 1 : 0);
}

$file = $options['file'];
if (!is_readable($file)) {
    cli_error("Cannot read --file=$file (does the container see this path? " .
              "The Makefile target bind-mounts the host directory at /data.)");
}

$sdmsbase = rtrim((string) $options['sdms-url'], '/');
$matchfield = (string) $options['match'];
if (!in_array($matchfield, ['idnumber', 'username', 'email'], true)) {
    cli_error("--match must be one of idnumber|username|email, got '$matchfield'");
}

$verbose  = (bool) $options['verbose'];
$dryrun   = (bool) $options['dry-run'];
$limit    = (int)  $options['limit'];
$sleepms  = max(0, (int) $options['sleep-ms']);

// --- Load the workbook --------------------------------------------------

cli_writeln("Workbook : $file");
cli_writeln("SDMS base: $sdmsbase");
cli_writeln("Match on : mdl_user.$matchfield");
cli_writeln("Mode     : " . ($dryrun ? 'DRY-RUN (no DB writes)' : 'WRITE'));
cli_writeln('');

// --- Decide which sheets to walk ---------------------------------------

$sheetparam = $options['sheet'];
if ($sheetparam === null || $sheetparam === '') {
    $sheetnames = xlsx_list_sheet_names($file);
    cli_writeln('Sheets   : ' . count($sheetnames) . ' (all in workbook)');
} else {
    $sheetnames = [(string) $sheetparam];
    cli_writeln('Sheets   : 1 (--sheet=' . $sheetparam . ')');
}
cli_writeln('');

global $DB;

$counters = [
    'sheets_done'    => 0,
    'sheets_skipped' => 0,
    'rows'           => 0,
    'no_code'        => 0,
    'duplicate'      => 0,
    'sdms_fail'      => 0,
    'no_schoolcode'  => 0,
    'user_not_found' => 0,
    'unchanged'      => 0,
    'updated'        => 0,
];

// In-process caches.
// $schoolnamecache : school_code -> school_name (or schoolCode fallback).
// $studentcache    : studentCode -> ['status' => ok|sdms_fail|no_schoolcode,
//                                    'schoolcode' => ?, 'institution' => ?].
// The student cache lets us hit SDMS at most once per code across the
// whole workbook — even if the same student appears in several sheets
// or twice in one sheet.
$schoolnamecache = [];
$studentcache    = [];
$codecolumnopt   = $options['code-column'];
$stoploop        = false;

foreach ($sheetnames as $sheetref) {
    if ($stoploop) {
        break;
    }
    [$title, $rows] = xlsx_load_sheet($file, $sheetref);
    $nrows = count($rows);
    cli_writeln(str_repeat('=', 60));
    cli_writeln("Sheet: $title  (data rows: " . max(0, $nrows - 1) . ')');

    if ($nrows < 2) {
        cli_writeln('  -> no data rows, skipping.');
        $counters['sheets_skipped']++;
        continue;
    }

    $headers = array_map(static function ($v) {
        return is_string($v) ? trim($v) : $v;
    }, $rows[0]);

    // Locate the student-code column for THIS sheet.
    $colidx = null;
    if ($codecolumnopt !== null && $codecolumnopt !== '') {
        foreach ($headers as $i => $h) {
            if (is_string($h) && strcasecmp(trim($h), $codecolumnopt) === 0) {
                $colidx = $i;
                break;
            }
        }
        if ($colidx === null) {
            cli_writeln("  -> code column '$codecolumnopt' not found in this sheet, skipping. " .
                        'Headers: ' . implode(' | ', array_map('strval', $headers)));
            $counters['sheets_skipped']++;
            continue;
        }
    } else {
        $candidates = [];
        foreach ($headers as $i => $h) {
            if (!is_string($h)) {
                continue;
            }
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower($h));
            if ($norm === '') {
                continue;
            }
            if (strpos($norm, 'student') !== false &&
                (strpos($norm, 'code') !== false || strpos($norm, 'number') !== false)) {
                $candidates[1] = $i;
            } else if ($norm === 'idnumber' ||
                       (strpos($norm, 'id') !== false && strpos($norm, 'number') !== false)) {
                $candidates[2] = $i;
            } else if ($norm === 'studentid' || $norm === 'studentcode') {
                $candidates[3] = $i;
            }
        }
        if ($candidates) {
            ksort($candidates);
            $colidx = reset($candidates);
        }
        if ($colidx === null) {
            cli_writeln('  -> no recognisable student-code header in this sheet, skipping. ' .
                        'Headers: ' . implode(' | ', array_map('strval', $headers)));
            $counters['sheets_skipped']++;
            continue;
        }
        cli_writeln("  Code col: '" . $headers[$colidx] . "' (column " . ($colidx + 1) . ', auto-detected)');
    }
    $counters['sheets_done']++;

    for ($r = 1; $r < $nrows; $r++) {
        $row  = $rows[$r];
        $code = isset($row[$colidx]) ? trim((string) $row[$colidx]) : '';

        if ($code === '') {
            $counters['no_code']++;
            continue;
        }
        // Defensive: numeric codes that came through as floats.
        if (is_numeric($code) && strpos($code, '.') !== false) {
            $code = rtrim(rtrim($code, '0'), '.');
        }

        $counters['rows']++;
        if ($limit > 0 && $counters['rows'] > $limit) {
            $counters['rows']--;
            $stoploop = true;
            cli_writeln("  -> --limit=$limit reached, stopping.");
            break;
        }

        // Seen this studentCode already in this run? Short-circuit — no
        // SDMS call, no DB write. The first occurrence already handled it
        // (either updating the user, or recording a failure reason).
        if (array_key_exists($code, $studentcache)) {
            $counters['duplicate']++;
            if ($verbose) {
                $prev = $studentcache[$code]['status'];
                cli_writeln("  [$code] duplicate (already processed: $prev)");
            }
            continue;
        }

        $url  = $sdmsbase . '/student?studentCode=' . rawurlencode($code);
        $body = sdms_fetch($url);
        if ($body === false) {
            $counters['sdms_fail']++;
            $studentcache[$code] = ['status' => 'sdms_fail'];
            if ($verbose) {
                cli_writeln("  [$code] SDMS request failed");
            }
            sdms_sleep($sleepms);
            continue;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['schoolCode'])) {
            $counters['no_schoolcode']++;
            $studentcache[$code] = ['status' => 'no_schoolcode'];
            if ($verbose) {
                cli_writeln("  [$code] no schoolCode in SDMS response");
            }
            sdms_sleep($sleepms);
            continue;
        }
        $schoolcode = trim((string) $data['schoolCode']);

        if (!array_key_exists($schoolcode, $schoolnamecache)) {
            $school = $DB->get_record('elby_schools',
                ['school_code' => $schoolcode], 'school_name', IGNORE_MISSING);
            $schoolnamecache[$schoolcode] = ($school && !empty($school->school_name))
                ? $school->school_name
                : $schoolcode;
        }
        $institution = $schoolnamecache[$schoolcode];

        // Mark as seen now — even if the user lookup below fails, we don't
        // want to re-hit SDMS for this same code on a later sheet.
        $studentcache[$code] = [
            'status'      => 'ok',
            'schoolcode'  => $schoolcode,
            'institution' => $institution,
        ];

        $user = $DB->get_record('user',
            [$matchfield => $code, 'deleted' => 0],
            'id, schoolcode, institution',
            IGNORE_MISSING
        );
        if (!$user) {
            $counters['user_not_found']++;
            if ($verbose) {
                cli_writeln("  [$code] no mdl_user with $matchfield='$code'");
            }
            sdms_sleep($sleepms);
            continue;
        }

        if ((string) $user->schoolcode === $schoolcode &&
            (string) $user->institution === $institution) {
            $counters['unchanged']++;
            if ($verbose) {
                cli_writeln("  [$code] user#{$user->id} already up to date ($schoolcode / $institution)");
            }
            sdms_sleep($sleepms);
            continue;
        }

        if ($dryrun) {
            cli_writeln("  [$code] DRY user#{$user->id}: " .
                        "schoolcode '{$user->schoolcode}' -> '$schoolcode', " .
                        "institution '{$user->institution}' -> '$institution'");
        } else {
            $DB->update_record('user', (object) [
                'id'           => $user->id,
                'schoolcode'   => $schoolcode,
                'institution'  => $institution,
                'timemodified' => time(),
            ]);
            if ($verbose) {
                cli_writeln("  [$code] user#{$user->id} updated: " .
                            "schoolcode='$schoolcode' institution='$institution'");
            }
        }
        $counters['updated']++;

        sdms_sleep($sleepms);
    }
}

// --- Summary ------------------------------------------------------------

cli_writeln('');
cli_writeln(str_repeat('-', 60));
cli_writeln(sprintf('Sheets processed   : %d', $counters['sheets_done']));
cli_writeln(sprintf('Sheets skipped     : %d', $counters['sheets_skipped']));
cli_writeln(sprintf('Rows processed     : %d', $counters['rows']));
cli_writeln(sprintf('Duplicate rows     : %d  (same studentCode, SDMS not re-called)', $counters['duplicate']));
cli_writeln(sprintf('Unique students    : %d', count($studentcache)));
cli_writeln(sprintf('Blank code rows    : %d', $counters['no_code']));
cli_writeln(sprintf('SDMS errors        : %d', $counters['sdms_fail']));
cli_writeln(sprintf('Missing schoolCode : %d', $counters['no_schoolcode']));
cli_writeln(sprintf('Moodle user missing: %d', $counters['user_not_found']));
cli_writeln(sprintf('Already up to date : %d', $counters['unchanged']));
cli_writeln(sprintf('%s: %d',
    $dryrun ? 'Would update       ' : 'Updated            ',
    $counters['updated']));

exit(0);

// -----------------------------------------------------------------------

/**
 * @param string $url
 * @param int $timeout Whole-request timeout in seconds.
 * @return string|false JSON body on success, false on HTTP/curl failure.
 */
function sdms_fetch(string $url, int $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        fwrite(STDERR, "SDMS $url -> HTTP $code" . ($err ? " ($err)" : '') . "\n");
        return false;
    }
    return $body;
}

function sdms_sleep(int $ms): void {
    if ($ms > 0) {
        usleep($ms * 1000);
    }
}

// -----------------------------------------------------------------------
// Minimal XLSX reader (ZipArchive + SimpleXML).
//
// XLSX is a ZIP archive containing XML parts. We only need enough to read
// a single sheet as a 2D array of strings:
//   - xl/sharedStrings.xml    : the string pool (cells of type "s" index here)
//   - xl/workbook.xml         : sheet list (names + r:id)
//   - xl/_rels/workbook.xml.rels : maps r:id -> sheet XML path
//   - xl/worksheets/sheetN.xml: the row/cell data
// -----------------------------------------------------------------------

/**
 * Load a sheet from an XLSX file and return [title, rows].
 *
 * @param string      $file       Absolute path to .xlsx file.
 * @param string|null $sheetparam Sheet name, 0-based index, or null for first.
 * @return array{0:string,1:array<int,array<int,?string>>}
 */
function xlsx_load_sheet(string $file, $sheetparam = null): array {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        cli_error("Cannot open as XLSX (not a zip archive?): $file");
    }

    // Build shared-string table.
    $shared = [];
    $sxml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sxml !== false) {
        $sx = @simplexml_load_string($sxml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else if (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }
    }

    // Resolve which sheet XML part to read.
    [$sheetpath, $sheettitle] = xlsx_resolve_sheet($zip, $sheetparam);

    $sheetxml = $zip->getFromName($sheetpath);
    $zip->close();
    if ($sheetxml === false) {
        cli_error("Sheet part not found in workbook: $sheetpath");
    }

    $sx = @simplexml_load_string($sheetxml);
    if ($sx === false) {
        cli_error("Failed to parse sheet XML: $sheetpath");
    }

    // Pass 1: collect sparse rows + track max column index.
    $sparse = [];
    $maxcol = -1;
    foreach ($sx->sheetData->row as $row) {
        $rowarr = [];
        foreach ($row->c as $c) {
            $ref  = (string) $c['r'];        // e.g. "B12"
            $col  = xlsx_col_index($ref);    // 0-based
            $type = (string) ($c['t'] ?? '');
            $val  = null;
            if ($type === 's') {
                $idx = (int) $c->v;
                $val = $shared[$idx] ?? '';
            } else if ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string) $c->is->t : '';
            } else if ($type === 'b') {
                $val = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
            } else if ($type === 'e' || $type === 'str') {
                $val = isset($c->v) ? (string) $c->v : '';
            } else {
                // Numeric or date; keep as string. Normalise trailing zeros
                // for whole numbers so studentCode "110109230152" doesn't
                // come through as "1.10109230152E+11".
                $raw = isset($c->v) ? (string) $c->v : '';
                if ($raw !== '' && is_numeric($raw)) {
                    // Avoid scientific notation for big integers.
                    $f = (float) $raw;
                    if (floor($f) === $f && abs($f) < 1e16) {
                        $val = number_format($f, 0, '.', '');
                    } else {
                        $val = $raw;
                    }
                } else {
                    $val = $raw;
                }
            }
            $rowarr[$col] = $val;
            if ($col > $maxcol) {
                $maxcol = $col;
            }
        }
        $sparse[] = $rowarr;
    }

    // Pass 2: densify so every row has the same number of columns.
    $rows = [];
    foreach ($sparse as $rowarr) {
        $dense = [];
        for ($i = 0; $i <= $maxcol; $i++) {
            $dense[$i] = array_key_exists($i, $rowarr) ? $rowarr[$i] : null;
        }
        // Skip completely empty rows.
        $hasvalue = false;
        foreach ($dense as $v) {
            if ($v !== null && $v !== '') {
                $hasvalue = true;
                break;
            }
        }
        if ($hasvalue) {
            $rows[] = $dense;
        }
    }

    return [$sheettitle, $rows];
}

/**
 * Pick which sheetN.xml to read.
 * @return array{0:string,1:string} [sheetPath, sheetTitle]
 */
function xlsx_resolve_sheet(ZipArchive $zip, $sheetparam): array {
    $wb   = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        // Fall back to the conventional first-sheet path.
        return ['xl/worksheets/sheet1.xml', 'Sheet1'];
    }
    $wbx  = @simplexml_load_string($wb);
    $relx = @simplexml_load_string($rels);
    if ($wbx === false || $relx === false) {
        return ['xl/worksheets/sheet1.xml', 'Sheet1'];
    }

    // r:id -> target path (relative to xl/)
    $relmap = [];
    foreach ($relx->Relationship as $rel) {
        $relmap[(string) $rel['Id']] = (string) $rel['Target'];
    }

    $sheets = [];
    foreach ($wbx->sheets->sheet as $sh) {
        $attrs = $sh->attributes('r', true); // r:id
        $rid   = (string) ($attrs['id'] ?? '');
        $sheets[] = [
            'name'   => (string) $sh['name'],
            'rid'    => $rid,
            'target' => $relmap[$rid] ?? '',
        ];
    }
    if (!$sheets) {
        return ['xl/worksheets/sheet1.xml', 'Sheet1'];
    }

    $picked = null;
    if ($sheetparam === null || $sheetparam === '') {
        $picked = $sheets[0];
    } else if (ctype_digit((string) $sheetparam)) {
        $picked = $sheets[(int) $sheetparam] ?? null;
    } else {
        foreach ($sheets as $s) {
            if (strcasecmp($s['name'], (string) $sheetparam) === 0) {
                $picked = $s;
                break;
            }
        }
    }
    if ($picked === null) {
        cli_error('Sheet not found: ' . $sheetparam .
                  ' (available: ' . implode(', ', array_column($sheets, 'name')) . ')');
    }

    $target = $picked['target'];
    // Targets are relative to xl/, e.g. "worksheets/sheet1.xml".
    $path = 'xl/' . ltrim($target, '/');
    return [$path, $picked['name']];
}

/**
 * Return the ordered list of sheet display names in an XLSX workbook.
 *
 * @return string[] e.g. ['IS ECLPE Y2 2025-2026', 'IS ECLPE Y3 2025-2026']
 */
function xlsx_list_sheet_names(string $file): array {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        cli_error("Cannot open as XLSX: $file");
    }
    $wb = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($wb === false) {
        return [];
    }
    $sx = @simplexml_load_string($wb);
    if ($sx === false) {
        return [];
    }
    $names = [];
    foreach ($sx->sheets->sheet as $sh) {
        $names[] = (string) $sh['name'];
    }
    return $names;
}

/**
 * Cell ref (e.g. "AB12") -> 0-based column index.
 */
function xlsx_col_index(string $ref): int {
    $letters = preg_replace('/[0-9]+/', '', $ref);
    $col = 0;
    $n = strlen($letters);
    for ($i = 0; $i < $n; $i++) {
        $col = $col * 26 + (ord(strtoupper($letters[$i])) - ord('A') + 1);
    }
    return $col - 1;
}
