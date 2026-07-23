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
 * RISE learner self-service correction form.
 *
 * Two entry points, one page: an SMS deep link carrying a single-use,
 * time-limited token (no login required — action_requested/rejected happen
 * pre-approval), or the profile badge for a logged-in linked learner
 * (session-authenticated, sesskey-protected).
 *
 * Handled as a normal server-side form POST: the token is validated before any
 * file is accepted, uploads pass a strict image/PDF allowlist + size limit +
 * antivirus, and files are stored through the File API (fileareas rise_idcard /
 * rise_nesaresult, itemid = correction id).
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_elby_dashboard\rate_limiter;
use local_elby_dashboard\rise_client;
use local_elby_dashboard\rise_token;
use local_elby_dashboard\rise_user_service;

$token = optional_param('t', '', PARAM_ALPHANUM);
$done = optional_param('done', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/elby_dashboard/rise_action.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('signup');
$PAGE->set_title(get_string('rise_action_title', 'local_elby_dashboard'));

// Public endpoint: throttle by IP before touching the token table. Views are
// throttled loosely (carrier-NAT groups share one IP during an SMS campaign);
// submissions more tightly.
if (data_submitted()) {
    rate_limiter::check('rise_action_submit', 20);
} else {
    rate_limiter::check('rise_action_view', 60);
}

/**
 * Render a status card and stop.
 *
 * @param string $heading Card heading.
 * @param string $body Card body text.
 * @param bool $success Whether this is a success (green) card.
 */
function local_elby_dashboard_rise_action_card(string $heading, string $body, bool $success = false): void {
    global $OUTPUT;
    echo $OUTPUT->header();
    $color = $success ? '#1a7f43' : '#b42318';
    echo html_writer::start_div('', ['style' => 'max-width:520px;margin:48px auto;padding:28px;background:#fff;'
        . 'border:1px solid #ecedf1;border-radius:16px;font-family:Inter,system-ui,sans-serif;']);
    echo html_writer::tag('h2', s($heading), ['style' => "margin:0 0 10px;font-size:20px;color:{$color};"]);
    echo html_writer::tag('p', s($body), ['style' => 'margin:0;color:#3b424f;font-size:14px;line-height:1.5;']);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    die;
}

// Post/Redirect/Get landing: a refresh after submitting must not resubmit the
// form (session path) or hit a confusing "link already used" error (token path).
if ($done) {
    local_elby_dashboard_rise_action_card(
        get_string('rise_action_done_title', 'local_elby_dashboard'),
        get_string('rise_action_done', 'local_elby_dashboard'),
        true
    );
}

// Resolve access: token deep link first, logged-in linked learner second.
$tokenrecord = null;
$viasession = false;
if ($token !== '') {
    [$status, $tokenrecord] = rise_token::check($token, rise_token::PURPOSE_CORRECTION);
    if ($status !== 'ok') {
        $messages = [
            'invalid' => get_string('rise_token_invalid', 'local_elby_dashboard'),
            'expired' => get_string('rise_token_expired', 'local_elby_dashboard'),
            'used' => get_string('rise_token_used', 'local_elby_dashboard'),
        ];
        local_elby_dashboard_rise_action_card(get_string('rise_action_title', 'local_elby_dashboard'),
            $messages[$status] ?? $messages['invalid']);
    }
    $review = $DB->get_record('elby_rise_reviews', [
        'campaignid' => $tokenrecord->campaignid,
        'applicantid' => $tokenrecord->applicantid,
    ]);
} else if (isloggedin() && !isguestuser()) {
    // Session path: resolve the learner's own review — linked by userid, or (for
    // pre-approval NESA actions with no link yet) matched by their National ID.
    $review = rise_user_service::find_review_for_user($USER) ?: false;
    $viasession = true;
} else {
    $review = false;
}

if (!$review) {
    local_elby_dashboard_rise_action_card(get_string('rise_action_title', 'local_elby_dashboard'),
        get_string('rise_token_invalid', 'local_elby_dashboard'));
}

// Corrections are only accepted while an action is actually outstanding —
// on BOTH entry points. Without this, a still-active token minted before the
// review was resolved (or the session path) would be a lingering write channel
// into RISE after approval.
if (!rise_user_service::action_outstanding($review)) {
    local_elby_dashboard_rise_action_card(
        get_string('rise_action_title', 'local_elby_dashboard'),
        get_string('rise_action_nothing_needed', 'local_elby_dashboard'),
        true
    );
}

/**
 * Validate an optional upload against size, MIME allowlist and antivirus.
 *
 * @param string $field $_FILES key.
 * @param array $errors Error list (appended to).
 * @return array|null ['tmp' => ..., 'filename' => ...] when a valid file was supplied.
 */
function local_elby_dashboard_rise_validate_upload(string $field, array &$errors): ?array {
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['name'])) {
        return null;
    }
    $label = get_string('rise_action_file_' . $field, 'local_elby_dashboard');
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $errors[] = get_string('rise_action_upload_failed', 'local_elby_dashboard', $label);
        return null;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        $errors[] = get_string('rise_action_upload_toobig', 'local_elby_dashboard', $label);
        return null;
    }
    // Server-side MIME sniffing with an image/PDF allowlist — never trust the extension.
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) {
        $errors[] = get_string('rise_action_upload_badtype', 'local_elby_dashboard', $label);
        return null;
    }
    try {
        \core\antivirus\manager::scan_file($file['tmp_name'], $file['name'], false);
    } catch (\core\antivirus\scanner_exception $e) {
        $errors[] = get_string('rise_action_upload_infected', 'local_elby_dashboard', $label);
        return null;
    }
    $filename = clean_param($file['name'], PARAM_FILE);
    if ($filename === '' || $filename === '.') {
        $filename = $field . '.' . $allowed[$mime];
    }
    return ['tmp' => $file['tmp_name'], 'filename' => $filename];
}

$names = rise_user_service::split_name((string) ($review->fullname ?? ''));
$prefill = [
    'firstname' => $names['firstname'],
    'lastname' => $names['lastname'],
    'nid' => (string) ($review->nid ?? ''),
    'note' => '',
];

$errors = [];
if (data_submitted() && optional_param('submitted', 0, PARAM_INT)) {
    if ($viasession) {
        require_sesskey();
    }

    $prefill['firstname'] = \core_text::substr(trim(optional_param('firstname', '', PARAM_TEXT)), 0, 100);
    $prefill['lastname'] = \core_text::substr(trim(optional_param('lastname', '', PARAM_TEXT)), 0, 100);
    $prefill['nid'] = \core_text::substr(trim(optional_param('nid', '', PARAM_TEXT)), 0, 50);
    $prefill['note'] = \core_text::substr(trim(optional_param('note', '', PARAM_TEXT)), 0, 1000);

    if ($prefill['firstname'] === '' || $prefill['lastname'] === '') {
        $errors[] = get_string('rise_action_names_required', 'local_elby_dashboard');
    }
    if ($prefill['nid'] !== '' && !rise_user_service::is_valid_nid($prefill['nid'])) {
        $errors[] = get_string('rise_action_nid_format', 'local_elby_dashboard');
    }

    $idcard = local_elby_dashboard_rise_validate_upload('idcard', $errors);
    $nesaresult = local_elby_dashboard_rise_validate_upload('nesaresult', $errors);

    if (!$errors) {
        // Serialize the submission on the per-applicant lock and re-check the
        // outstanding action against a FRESH row read — a reviewer may have
        // resolved this review since the page loaded (the outer check above ran on
        // a pre-lock read). The token is consumed inside the lock too, so a
        // concurrent POST can't submit twice. The card helper die()s, so any
        // early-exit rendering happens AFTER the lock is released.
        $service = new rise_user_service();
        $outcome = $service->with_applicant_lock($review->campaignid, $review->applicantid,
            function () use ($review, $tokenrecord, $prefill, $idcard, $nesaresult) {
                global $DB;

                $fresh = $DB->get_record('elby_rise_reviews', ['id' => $review->id]);
                if (!$fresh || !rise_user_service::action_outstanding($fresh)) {
                    return ['status' => 'resolved'];
                }
                if ($tokenrecord && !rise_token::try_consume($tokenrecord->id)) {
                    return ['status' => 'used'];
                }

                $now = time();
                $correction = (object) [
                    'campaignid' => $review->campaignid,
                    'applicantid' => $review->applicantid,
                    'firstname' => $prefill['firstname'],
                    'lastname' => $prefill['lastname'],
                    'nid' => $prefill['nid'],
                    'note' => $prefill['note'],
                    'status' => 'pending',
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $correction->id = $DB->insert_record('elby_rise_corrections', $correction);

                $fs = get_file_storage();
                $context = context_system::instance();
                foreach (['rise_idcard' => $idcard, 'rise_nesaresult' => $nesaresult] as $filearea => $upload) {
                    if ($upload !== null) {
                        $fs->create_file_from_pathname([
                            'contextid' => $context->id,
                            'component' => 'local_elby_dashboard',
                            'filearea' => $filearea,
                            'itemid' => $correction->id,
                            'filepath' => '/',
                            'filename' => $upload['filename'],
                        ], $upload['tmp']);
                    }
                }

                // Surface the resubmission to the reviewer (separate from provisioningaction).
                $DB->update_record('elby_rise_reviews', (object) [
                    'id' => $review->id,
                    'correctionstatus' => 'resubmitted',
                    'timemodified' => $now,
                ]);

                return ['status' => 'ok', 'correctionid' => (int) $correction->id];
            });

        if ($outcome['status'] === 'resolved') {
            // The reviewer resolved this action before the submission landed.
            local_elby_dashboard_rise_action_card(
                get_string('rise_action_title', 'local_elby_dashboard'),
                get_string('rise_action_nothing_needed', 'local_elby_dashboard'),
                true
            );
        }
        if ($outcome['status'] === 'used') {
            local_elby_dashboard_rise_action_card(get_string('rise_action_title', 'local_elby_dashboard'),
                get_string('rise_token_used', 'local_elby_dashboard'));
        }

        // Push corrected name/NID back to RISE AFTER releasing the lock — the PATCH
        // is a best-effort network round-trip (up to ~90s with retries) that must
        // not hold the applicant lock. Failure or rejection is non-fatal: the
        // correction row stores the values locally, and risesynced=0 tells the
        // reviewer the values did NOT reach RISE.
        try {
            $client = new rise_client();
            $fields = ['fullName' => trim($prefill['lastname'] . ' ' . $prefill['firstname'])];
            if ($prefill['nid'] !== '') {
                $fields['nid'] = $prefill['nid'];
            }
            $response = $client->patch_applicant($review->applicantid, $fields);
            if (!empty($response['success']) && !empty($response['applicantUpdated'])) {
                $DB->set_field('elby_rise_corrections', 'risesynced', 1, ['id' => $outcome['correctionid']]);
            } else {
                debugging('RISE correction write-back rejected: ' . json_encode($response), DEBUG_DEVELOPER);
            }
        } catch (\Throwable $e) {
            debugging('RISE correction write-back failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Post/Redirect/Get so a refresh can't double-submit.
        redirect(new moodle_url('/local/elby_dashboard/rise_action.php', ['done' => 1]));
    }
}

echo $OUTPUT->header();
$inputstyle = 'width:100%;height:40px;border:1px solid #dfe3ea;border-radius:10px;padding:0 12px;margin-bottom:14px;box-sizing:border-box;';
$labelstyle = 'display:block;font-size:12px;font-weight:700;color:#6b7280;margin-bottom:6px;';
?>
<div style="max-width:560px;margin:40px auto;padding:28px;background:#fff;border:1px solid #ecedf1;border-radius:16px;font-family:Inter,system-ui,sans-serif;">
    <h2 style="margin:0 0 6px;font-size:20px;color:#161b26;"><?php echo s(get_string('rise_action_title', 'local_elby_dashboard')); ?></h2>
    <p style="margin:0 0 16px;color:#6b7280;font-size:13.5px;">
        <?php echo s(get_string('rise_action_intro', 'local_elby_dashboard')); ?>
    </p>
    <?php if (trim((string) $review->comment) !== ''): ?>
        <div style="margin:0 0 16px;padding:12px 14px;border:1px solid #f3e1c0;background:#fff8ee;border-radius:10px;color:#8a5a08;font-size:13px;">
            <b><?php echo s(get_string('rise_action_reviewersaid', 'local_elby_dashboard')); ?></b>
            <?php echo s($review->comment); ?>
        </div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div style="margin:0 0 12px;padding:10px 14px;border:1px solid #f3c9c9;background:#fdf3f3;border-radius:10px;color:#b42318;font-size:13px;">
            <?php echo s($error); ?>
        </div>
    <?php endforeach; ?>
    <form method="post" action="<?php echo s($PAGE->url->out(false)); ?>" enctype="multipart/form-data">
        <?php if ($token !== ''): ?>
            <input type="hidden" name="t" value="<?php echo s($token); ?>">
        <?php else: ?>
            <input type="hidden" name="sesskey" value="<?php echo s(sesskey()); ?>">
        <?php endif; ?>
        <input type="hidden" name="submitted" value="1">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div>
                <label style="<?php echo $labelstyle; ?>"><?php echo s(get_string('firstname')); ?> *</label>
                <input type="text" name="firstname" required maxlength="100" value="<?php echo s($prefill['firstname']); ?>" style="<?php echo $inputstyle; ?>">
            </div>
            <div>
                <label style="<?php echo $labelstyle; ?>"><?php echo s(get_string('lastname')); ?> *</label>
                <input type="text" name="lastname" required maxlength="100" value="<?php echo s($prefill['lastname']); ?>" style="<?php echo $inputstyle; ?>">
            </div>
        </div>

        <label style="<?php echo $labelstyle; ?>"><?php echo s(get_string('rise_action_nid_label', 'local_elby_dashboard')); ?></label>
        <input type="text" name="nid" inputmode="numeric" pattern="[0-9]{16}" maxlength="16"
               value="<?php echo s($prefill['nid']); ?>" style="<?php echo $inputstyle; ?>">

        <label style="<?php echo $labelstyle; ?>"><?php echo s(get_string('rise_action_file_idcard', 'local_elby_dashboard')); ?></label>
        <input type="file" name="idcard" accept=".jpg,.jpeg,.png,.webp,.pdf" style="margin-bottom:14px;font-size:13px;">

        <label style="<?php echo $labelstyle; ?>"><?php echo s(get_string('rise_action_file_nesaresult', 'local_elby_dashboard')); ?></label>
        <input type="file" name="nesaresult" accept=".jpg,.jpeg,.png,.webp,.pdf" style="margin-bottom:14px;font-size:13px;">

        <label style="<?php echo $labelstyle; ?>"><?php echo s(get_string('rise_action_note_label', 'local_elby_dashboard')); ?></label>
        <textarea name="note" maxlength="1000" style="width:100%;min-height:70px;border:1px solid #dfe3ea;border-radius:10px;padding:10px 12px;margin-bottom:18px;box-sizing:border-box;font-family:inherit;"><?php echo s($prefill['note']); ?></textarea>

        <button type="submit" class="btn btn-primary" style="width:100%;background:#005198;border-color:#005198;">
            <?php echo s(get_string('rise_action_submit', 'local_elby_dashboard')); ?>
        </button>
    </form>
</div>
<?php
echo $OUTPUT->footer();
