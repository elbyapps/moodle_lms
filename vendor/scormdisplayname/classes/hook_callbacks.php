<?php
namespace local_scormdisplayname;

defined('MOODLE_INTERNAL') || die();

class hook_callbacks {
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $PAGE, $COURSE, $CFG, $DB;

        // Safely resolve the course ID using the global $COURSE object.
        // This prevents Moodle from throwing errors on admin or system-level pages.
        $courseid = 0;
        if (!empty($COURSE) && isset($COURSE->id)) {
            $courseid = (int)$COURSE->id;
        }

        // If there's no valid course, or we are on the Site Home (ID 1), stop.
        if ($courseid <= 1) {
            return;
        }

        require_once($CFG->dirroot . '/local/scormdisplayname/lib.php');
        
        // 1. If the custom checkbox is NOT ticked, do nothing.
        if (!local_scormdisplayname_is_enabled_for_course($courseid)) {
            return;
        }

        // 2. The checkbox IS ticked! Add a custom CSS class to the <body> tag.
        $PAGE->add_body_class('custom-scorm-styling');

        // 3. Stop here if we are not on a SCORM activity page.
        // $PAGE->cm returns null if not set, so this check is perfectly safe.
        if ($PAGE->cm === null || $PAGE->cm->modname !== 'scorm') {
            return;
        }

        $fulltitle = $DB->get_field('local_scormdisplayname', 'fulltitle',
            ['scormid' => $PAGE->cm->instance]);
        
        if ($fulltitle === false || $fulltitle === '') {
            return;
        }

        $formatted = format_string($fulltitle, true, ['context' => $PAGE->context]);

        if (method_exists($PAGE->cm, 'set_name')) {
            $PAGE->cm->set_name($formatted);
        }

        if ($PAGE->activityheader !== null) {
            $PAGE->activityheader->set_title('');
        }

        if ($PAGE->activityrecord !== null) {
            $PAGE->activityrecord->name = $formatted;
        }

        $shortname = $COURSE ? format_string($COURSE->shortname, true, ['context' => $PAGE->context]) : '';
        $PAGE->set_title(strip_tags(($shortname ? $shortname . ': ' : '') . $formatted));
    }
}
