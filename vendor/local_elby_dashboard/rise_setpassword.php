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
 * RISE learner set-password page (public, token-gated).
 *
 * The welcome SMS carries a single-use, time-limited token; this page resolves
 * it by hash and lets the learner set their first password. Works for accounts
 * whose only email is the synthetic learner domain (core forgot_password.php
 * is email-oriented and can't help them).
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_elby_dashboard\rate_limiter;
use local_elby_dashboard\rise_token;

$token = optional_param('t', '', PARAM_ALPHANUM);
$done = optional_param('done', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/elby_dashboard/rise_setpassword.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('signup');
$PAGE->set_title(get_string('rise_setpassword_title', 'local_elby_dashboard'));

// Public endpoint: throttle by IP before touching the token table. Views are
// throttled loosely (carrier-NAT groups share one IP during an SMS campaign);
// submissions more tightly.
if (data_submitted()) {
    rate_limiter::check('rise_setpassword_submit', 20);
} else {
    rate_limiter::check('rise_setpassword_view', 60);
}

/**
 * Render a status card and stop.
 *
 * @param string $heading Card heading.
 * @param string $body Card body text.
 * @param bool $success Whether this is a success (green) card.
 * @param string|null $cta Optional link URL.
 * @param string|null $ctalabel Optional link label.
 */
function local_elby_dashboard_rise_card(string $heading, string $body, bool $success = false,
        ?string $cta = null, ?string $ctalabel = null): void {
    global $OUTPUT;
    echo $OUTPUT->header();
    $color = $success ? '#1a7f43' : '#b42318';
    echo html_writer::start_div('', ['style' => 'max-width:460px;margin:48px auto;padding:28px;background:#fff;'
        . 'border:1px solid #ecedf1;border-radius:16px;font-family:Inter,system-ui,sans-serif;']);
    echo html_writer::tag('h2', s($heading), ['style' => "margin:0 0 10px;font-size:20px;color:{$color};"]);
    echo html_writer::tag('p', s($body), ['style' => 'margin:0 0 16px;color:#3b424f;font-size:14px;line-height:1.5;']);
    if ($cta !== null) {
        echo html_writer::link($cta, $ctalabel, ['class' => 'btn btn-primary',
            'style' => 'background:#005198;border-color:#005198;']);
    }
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    die;
}

// Post/Redirect/Get landing: a refresh after success must not re-POST the
// (already consumed) token and show a confusing "already used" error.
if ($done) {
    local_elby_dashboard_rise_card(
        get_string('rise_setpassword_done_title', 'local_elby_dashboard'),
        get_string('rise_setpassword_done_generic', 'local_elby_dashboard'),
        true,
        (new moodle_url('/login/index.php'))->out(false),
        get_string('login')
    );
}

[$status, $record] = rise_token::check($token, rise_token::PURPOSE_SETPASSWORD);
if ($status !== 'ok') {
    $messages = [
        'invalid' => get_string('rise_token_invalid', 'local_elby_dashboard'),
        'expired' => get_string('rise_token_expired', 'local_elby_dashboard'),
        'used' => get_string('rise_token_used', 'local_elby_dashboard'),
    ];
    local_elby_dashboard_rise_card(get_string('rise_setpassword_title', 'local_elby_dashboard'),
        $messages[$status] ?? $messages['invalid']);
}

$user = $DB->get_record('user', ['id' => $record->userid, 'deleted' => 0, 'suspended' => 0]);
if (!$user) {
    local_elby_dashboard_rise_card(get_string('rise_setpassword_title', 'local_elby_dashboard'),
        get_string('rise_token_invalid', 'local_elby_dashboard'));
}

// The welcome token sets the FIRST password only. Once the learner has set a
// password or logged in through any path, the token is spent even if unused —
// don't let a leftover welcome link reset an established account's password.
$mustchange = (int) get_user_preferences('auth_forcepasswordchange', 0, $user->id) === 1;
if (!$mustchange || (int) $user->firstaccess > 0) {
    rise_token::consume($record->id);
    local_elby_dashboard_rise_card(get_string('rise_setpassword_title', 'local_elby_dashboard'),
        get_string('rise_token_used', 'local_elby_dashboard'));
}

$errors = [];
if (data_submitted() && optional_param('submitted', 0, PARAM_INT)) {
    $password = (string) optional_param('password', '', PARAM_RAW);
    $password2 = (string) optional_param('password2', '', PARAM_RAW);

    if ($password !== $password2) {
        $errors[] = get_string('rise_password_nomatch', 'local_elby_dashboard');
    } else {
        $errmsg = '';
        if (!check_password_policy($password, $errmsg, $user)) {
            $errors[] = strip_tags($errmsg);
        }
    }

    if (!$errors) {
        // Atomically consume the token BEFORE changing the password, so a
        // concurrent POST with the same token cannot set a second password.
        if (!rise_token::try_consume($record->id)) {
            local_elby_dashboard_rise_card(get_string('rise_setpassword_title', 'local_elby_dashboard'),
                get_string('rise_token_used', 'local_elby_dashboard'));
        }
        update_internal_user_password($user, $password, false);
        unset_user_preference('auth_forcepasswordchange', $user);
        // Post/Redirect/Get so a refresh can't re-POST the consumed token.
        redirect(new moodle_url('/local/elby_dashboard/rise_setpassword.php', ['done' => 1]));
    }
}

echo $OUTPUT->header();
?>
<div style="max-width:460px;margin:48px auto;padding:28px;background:#fff;border:1px solid #ecedf1;border-radius:16px;font-family:Inter,system-ui,sans-serif;">
    <h2 style="margin:0 0 6px;font-size:20px;color:#161b26;"><?php echo s(get_string('rise_setpassword_title', 'local_elby_dashboard')); ?></h2>
    <p style="margin:0 0 18px;color:#6b7280;font-size:13.5px;">
        <?php echo s(get_string('rise_setpassword_intro', 'local_elby_dashboard', $user->username)); ?>
    </p>
    <?php foreach ($errors as $error): ?>
        <div style="margin:0 0 12px;padding:10px 14px;border:1px solid #f3c9c9;background:#fdf3f3;border-radius:10px;color:#b42318;font-size:13px;">
            <?php echo s($error); ?>
        </div>
    <?php endforeach; ?>
    <form method="post" action="<?php echo s($PAGE->url->out(false)); ?>">
        <input type="hidden" name="t" value="<?php echo s($token); ?>">
        <input type="hidden" name="submitted" value="1">
        <label style="display:block;font-size:12px;font-weight:700;color:#6b7280;margin-bottom:6px;">
            <?php echo s(get_string('newpassword')); ?>
        </label>
        <input type="password" name="password" required autocomplete="new-password"
               style="width:100%;height:40px;border:1px solid #dfe3ea;border-radius:10px;padding:0 12px;margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#6b7280;margin-bottom:6px;">
            <?php echo s(get_string('rise_password_confirm', 'local_elby_dashboard')); ?>
        </label>
        <input type="password" name="password2" required autocomplete="new-password"
               style="width:100%;height:40px;border:1px solid #dfe3ea;border-radius:10px;padding:0 12px;margin-bottom:18px;">
        <button type="submit" class="btn btn-primary" style="width:100%;background:#005198;border-color:#005198;">
            <?php echo s(get_string('rise_setpassword_submit', 'local_elby_dashboard')); ?>
        </button>
    </form>
</div>
<?php
echo $OUTPUT->footer();
