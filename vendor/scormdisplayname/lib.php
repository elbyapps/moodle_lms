<?php
// Pure local plugin that lets a teacher give a SCORM activity two names:
//   * "Name" (existing field)        - heading shown on the activity page after click.
//   * "Section display name" (new)   - link label shown on the course section page.
// Implemented via Moodle's coursemodule_* plugin form callbacks; no core files are modified.

defined('MOODLE_INTERNAL') || die();

/**
 * Per-course opt-in gate.
 *
 * If a course custom field with shortname "enable_scormdisplayname" exists, the plugin
 * only activates on courses where that field is ticked. If the field has NOT been
 * created by the site admin, the plugin behaves as before (site-wide) so installs
 * stay backwards-compatible.
 */
function local_scormdisplayname_is_enabled_for_course($courseid): bool {
    $courseid = (int)$courseid;
    if ($courseid <= 0) {
        return false;
    }

    // In-memory cache ensures consistent results across multiple checks in the same page load.
    static $course_status_cache = [];
    if (isset($course_status_cache[$courseid])) {
        return $course_status_cache[$courseid];
    }

    try {
        global $DB;
        
        $field = $DB->get_record('customfield_field', ['shortname' => 'enable_scormdisplayname']);
        if (!$field) {
            $course_status_cache[$courseid] = true;
            return true;
        }

        $data = $DB->get_record('customfield_data', ['fieldid' => $field->id, 'instanceid' => $courseid]);
        if ($data !== false) { // Strict check to ensure DB didn't fail
            $course_status_cache[$courseid] = !empty($data->intvalue);
            return $course_status_cache[$courseid];
        }

        $configdata = json_decode($field->configdata, true);
        if (is_array($configdata) && !empty($configdata['checkbydefault'])) {
            $course_status_cache[$courseid] = true;
            return true;
        }
        
    } catch (\Throwable $e) {
        $course_status_cache[$courseid] = false;
        return false;
    }
    
    $course_status_cache[$courseid] = false;
    return false;
}


/**
 * Resolve the module name from a coursemodule form wrapper across Moodle 4.5 / 5.x.
 * Different Moodle versions expose either "modulename" or "modname" on the current data object.
 */
function local_scormdisplayname_modname_from_form($formwrapper): ?string {
    $current = $formwrapper->get_current();
    if (!empty($current->modulename)) {
        return $current->modulename;
    }
    if (!empty($current->modname)) {
        return $current->modname;
    }
    return null;
}

/**
 * Inject the "Section display name" field into the SCORM mod_form.
 */
function local_scormdisplayname_coursemodule_standard_elements($formwrapper, $mform) {
    if (local_scormdisplayname_modname_from_form($formwrapper) !== 'scorm') {
        return;
    }
    
    // PHP 8 safe course extraction (get_course can return an int or an object).
    $course = $formwrapper->get_course();
    $courseid = is_object($course) ? $course->id : (int)$course;
    
    if (!$courseid || !local_scormdisplayname_is_enabled_for_course($courseid)) {
        return;
    }
    
    $mform->addElement(
        'text',
        'sectiondisplayname',
        get_string('sectiondisplayname', 'local_scormdisplayname'),
        ['size' => 64]
    );
    $mform->setType('sectiondisplayname', PARAM_TEXT);
    $mform->addHelpButton('sectiondisplayname', 'sectiondisplayname', 'local_scormdisplayname');
    
    // Place the new field directly after the existing Name field for natural reading order.
    if ($mform->elementExists('name') && $mform->elementExists('sectiondisplayname')) {
        $element = $mform->removeElement('sectiondisplayname', false);
        $mform->insertElementBefore($element, 'introeditor');
    }
}

/**
 * After the form's data is set (edit screen), swap the displayed values so the teacher sees the
 * long activity-page title in "Name" and the short label in "Section display name".
 */
function local_scormdisplayname_coursemodule_definition_after_data($formwrapper, $mform) {
    if (local_scormdisplayname_modname_from_form($formwrapper) !== 'scorm') {
        return;
    }
    
    $course = $formwrapper->get_course();
    $courseid = is_object($course) ? $course->id : (int)$course;
    
    if (!$courseid || !local_scormdisplayname_is_enabled_for_course($courseid)) {
        return;
    }
    
    $cm = $formwrapper->get_coursemodule();
    if (!$cm) {
        // Creating a new activity - nothing to preload.
        return;
    }
    
    global $DB;
    $fulltitle = $DB->get_field('local_scormdisplayname', 'fulltitle', ['scormid' => $cm->instance]);
    if ($fulltitle === false || $fulltitle === '') {
        return;
    }
    $sectionname = $DB->get_field('scorm', 'name', ['id' => $cm->instance]);
    
    if ($mform->elementExists('name')) {
        $mform->getElement('name')->setValue($fulltitle);
    }
    if ($mform->elementExists('sectiondisplayname')) {
        $mform->getElement('sectiondisplayname')->setValue($sectionname);
    }
}

/**
 * After the SCORM record is saved, persist the swap:
 *   - Store the user's "Name" input as fulltitle in our table.
 *   - Overwrite scorm.name with the section display name.
 */
function local_scormdisplayname_coursemodule_edit_post_actions($data, $course) {
    if (($data->modulename ?? $data->modname ?? null) !== 'scorm') {
        return $data;
    }
    if (empty($data->instance)) {
        return $data;
    }
    
    $courseid = is_object($course) ? $course->id : (int)$course;
    
    if (!local_scormdisplayname_is_enabled_for_course($courseid)) {
        return $data;
    }
    
    global $DB;

    $fulltitle = (string)($data->name ?? '');
    $sectionname = trim((string)($data->sectiondisplayname ?? ''));

    if ($sectionname === '' || $sectionname === $fulltitle) {
        // No override - clean up any leftover record from a previous override and exit.
        $DB->delete_records('local_scormdisplayname', ['scormid' => $data->instance]);
        return $data;
    }

    $existing = $DB->get_record('local_scormdisplayname', ['scormid' => $data->instance]);
    if ($existing) {
        $existing->fulltitle = $fulltitle;
        $existing->timemodified = time();
        $DB->update_record('local_scormdisplayname', $existing);
    } else {
        $DB->insert_record('local_scormdisplayname', (object)[
            'scormid' => $data->instance,
            'fulltitle' => $fulltitle,
            'timemodified' => time(),
        ]);
    }

    // Overwrite the scorm.name so cm_info->name shows the short label. 
    $DB->set_field('scorm', 'name', $sectionname, ['id' => $data->instance]);

    // Moodle 5.x calls rebuild_course_cache BEFORE plugin_extend_coursemodule_edit_post_actions,
    // so the cache snapshot already has the stale (user-typed) name. Invalidate it now.
    rebuild_course_cache($courseid, true, true);

    return $data;
}
