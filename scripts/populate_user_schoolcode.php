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

// Moodle ships PhpSpreadsheet under lib/phpspreadsheet with a Composer
// autoloader. This is the supported way to read XLSX from a CLI script.
$autoload = $CFG->libdir . '/phpspreadsheet/vendor/autoload.php';
if (!is_readable($autoload)) {
    cli_error("PhpSpreadsheet autoloader not found at $autoload. " .
              "Is this Moodle 4.x/5.x with the bundled phpspreadsheet?");
}
require_once($autoload);

use PhpOffice\PhpSpreadsheet\IOFactory;

[$options, $unrecognized] = cli_get_params(
    [
        'help'         => false,
        'file'         => null,
        'sdms-url'     => getenv('SDMS_URL') ?: 'http://100.87.223.50:8082/sdms/api',
        'code-column'  => null,         // header name; auto-detected when null
        'sheet'        => null,         // sheet name or 0-based index
        'match'        => 'idnumber',   // idnumber | username | email
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
  --sheet=NAME|N      Sheet to read (name, or 0-based index). Default: 0.
  --match=FIELD       Moodle user field to identify users by:
                      idnumber (default), username, or email.
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

$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($file);

// Pick the sheet.
$sheetparam = $options['sheet'];
if ($sheetparam === null || $sheetparam === '') {
    $sheet = $spreadsheet->getSheet(0);
} else if (ctype_digit((string) $sheetparam)) {
    $sheet = $spreadsheet->getSheet((int) $sheetparam);
} else {
    $sheet = $spreadsheet->getSheetByName((string) $sheetparam);
    if (!$sheet) {
        cli_error("Sheet not found: $sheetparam");
    }
}
cli_writeln('Sheet    : ' . $sheet->getTitle());

$rows = $sheet->toArray(null, true, true, false);
$nrows = count($rows);
if ($nrows < 2) {
    cli_error('Sheet has no data rows (need at least a header + one row).');
}

$headers = array_map(static function ($v) {
    return is_string($v) ? trim($v) : $v;
}, $rows[0]);

// --- Locate the student-code column ------------------------------------

$colidx = null;
$codecolumn = $options['code-column'];
if ($codecolumn !== null && $codecolumn !== '') {
    foreach ($headers as $i => $h) {
        if (is_string($h) && strcasecmp(trim($h), $codecolumn) === 0) {
            $colidx = $i;
            break;
        }
    }
    if ($colidx === null) {
        cli_error("Code column '$codecolumn' not found. Headers: " .
                  implode(' | ', array_map('strval', $headers)));
    }
} else {
    foreach ($headers as $i => $h) {
        if (!is_string($h)) {
            continue;
        }
        $lh = strtolower($h);
        if (strpos($lh, 'student') !== false &&
            (strpos($lh, 'code') !== false || strpos($lh, 'number') !== false)) {
            $colidx = $i;
            break;
        }
    }
    if ($colidx === null) {
        cli_error("Could not auto-detect a student-code column. " .
                  "Pass --code-column=\"...\". Headers: " .
                  implode(' | ', array_map('strval', $headers)));
    }
    cli_writeln("Code col : '" . $headers[$colidx] . "' (column " . ($colidx + 1) . ", auto-detected)");
}
cli_writeln('Rows     : ' . ($nrows - 1));
cli_writeln('');

// --- Walk the rows ------------------------------------------------------

global $DB;

$counters = [
    'rows'           => 0,
    'no_code'        => 0,
    'sdms_fail'      => 0,
    'no_schoolcode'  => 0,
    'user_not_found' => 0,
    'unchanged'      => 0,
    'updated'        => 0,
];

// Tiny in-process cache: school_code -> school_name (or schoolCode fallback).
$schoolnamecache = [];

for ($r = 1; $r < $nrows; $r++) {
    $row = $rows[$r];
    $code = isset($row[$colidx]) ? trim((string) $row[$colidx]) : '';

    if ($code === '') {
        $counters['no_code']++;
        continue;
    }
    // PhpSpreadsheet may have read numeric codes as floats — normalise.
    if (is_numeric($code) && strpos($code, '.') !== false) {
        $code = rtrim(rtrim($code, '0'), '.');
    }

    $counters['rows']++;
    if ($limit > 0 && $counters['rows'] > $limit) {
        $counters['rows']--;
        break;
    }

    // Hit SDMS.
    $url = $sdmsbase . '/student?studentCode=' . rawurlencode($code);
    $body = sdms_fetch($url);
    if ($body === false) {
        $counters['sdms_fail']++;
        if ($verbose) {
            cli_writeln("  [$code] SDMS request failed");
        }
        sdms_sleep($sleepms);
        continue;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['schoolCode'])) {
        $counters['no_schoolcode']++;
        if ($verbose) {
            cli_writeln("  [$code] no schoolCode in SDMS response");
        }
        sdms_sleep($sleepms);
        continue;
    }
    $schoolcode = trim((string) $data['schoolCode']);

    // Resolve institution name: prefer the cached SDMS metadata.
    if (!array_key_exists($schoolcode, $schoolnamecache)) {
        $school = $DB->get_record('elby_schools',
            ['school_code' => $schoolcode], 'school_name', IGNORE_MISSING);
        $schoolnamecache[$schoolcode] = ($school && !empty($school->school_name))
            ? $school->school_name
            : $schoolcode;
    }
    $institution = $schoolnamecache[$schoolcode];

    // Locate the Moodle user.
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
            cli_writeln("  [$code] user#{$user->id} already up to date " .
                        "($schoolcode / $institution)");
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

// --- Summary ------------------------------------------------------------

cli_writeln('');
cli_writeln(str_repeat('-', 60));
cli_writeln(sprintf('Rows processed     : %d', $counters['rows']));
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
