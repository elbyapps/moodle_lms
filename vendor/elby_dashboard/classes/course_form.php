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
 * Course edit-form hook callbacks for local_elby_dashboard.
 *
 * For creators whose usertype profile field is "Teacher", adds required
 * Trade + Level selects (populated from the teacher's TDMP specialities) to the
 * course edit form, and persists the choice to elby_course_meta on save. The
 * curriculum module/courseId select is Phase 2 (needs curricula API scope).
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Course edit-form hook callbacks.
 */
class course_form {

    /**
     * The eligible classifier user type for the current user, or '' if none.
     *
     * @return string 'Teacher', 'RTB Staff', or '' (not eligible).
     */
    private static function eligible_type(): string {
        global $USER, $CFG;
        if (!course_enricher::is_enabled()) {
            return '';
        }
        require_once($CFG->dirroot . '/local/elby_dashboard/lib.php');
        $type = local_elby_dashboard_get_user_type((int) $USER->id);
        return in_array($type, ['Teacher', 'RTB Staff'], true) ? $type : '';
    }

    /**
     * Add the Trade + Level fields to the course edit form.
     *
     * Teachers pick from their specialities (with a trade->level cascade); RTB
     * Staff pick from the full canonical trade list with levels 1-5.
     *
     * @param \core_course\hook\after_form_definition $hook
     */
    public static function definition(\core_course\hook\after_form_definition $hook): void {
        global $USER, $PAGE;

        $type = self::eligible_type();
        if ($type === '') {
            return;
        }

        $usespecialities = false;
        $trades = [];
        $levels = [];
        $map = [];

        if ($type === 'Teacher') {
            $specs = course_enricher::get_teacher_specialities((int) $USER->id);
            if (!empty($specs)) {
                $usespecialities = true;
                $namemap = course_enricher::get_trade_name_map();
                foreach ($specs as $s) {
                    $name = $namemap[$s['trade_code']] ?? $s['trade_name'];
                    $trades[$s['trade_code']] = $name . ' (' . $s['trade_code'] . ')';
                    if ($s['level'] !== '') {
                        $levels[$s['level']] = $s['level_name'];
                        $map[$s['trade_code']][$s['level']] = $s['level_name'];
                    }
                }
            }
        }

        if (!$usespecialities) {
            // RTB Staff (or a teacher with no specialities): full trade list + levels 1-5.
            $trades = course_enricher::get_trade_options();
            if (empty($trades)) {
                return; // Gateway unavailable — do not block course editing.
            }
            for ($i = 1; $i <= 5; $i++) {
                $levels[(string) $i] = get_string('levelnumber', 'local_elby_dashboard', $i);
            }
        }

        $mform = $hook->mform;
        $mform->addElement('header', 'elbytvet', get_string('coursetvet_heading', 'local_elby_dashboard'));
        $mform->setExpanded('elbytvet');

        $mform->addElement('select', 'elbytrade', get_string('coursetrade', 'local_elby_dashboard'),
            ['' => get_string('choosedots')] + $trades);

        $mform->addElement('select', 'elbylevel', get_string('courselevel', 'local_elby_dashboard'),
            ['' => get_string('choosedots')] + $levels);

        // Searchable module (subject) field, fed live from TDMP via an AJAX webservice.
        // Optional, and not trade-bound: a module can belong to many trades.
        $mform->addElement('autocomplete', 'elbymodule', get_string('coursemodule', 'local_elby_dashboard'), [], [
            'ajax' => 'local_elby_dashboard/module_selector',
            'placeholder' => get_string('searchmodules', 'local_elby_dashboard'),
            'noselectionstring' => get_string('choosedots'),
            'casesensitive' => false,
        ]);
        // Carries the chosen module's label so it can be stored and re-displayed without an extra fetch.
        $mform->addElement('hidden', 'elbymodulename', '');
        $mform->setType('elbymodulename', PARAM_TEXT);

        // Capture the selected label into the hidden field; re-scope on trade change.
        $modulejs = <<<'JS'
require(['jquery'], function($) {
    var $module = $('#id_elbymodule'), $name = $('input[name="elbymodulename"]');
    if ($module.length && $name.length) {
        $module.on('change', function() {
            $name.val($module.find('option:selected').text() || '');
        });
    }
});
JS;
        $PAGE->requires->js_amd_inline($modulejs);

        if (!$usespecialities || empty($map)) {
            return; // No cascade in full-list mode.
        }

        // Cascade: narrow the Level options to the selected Trade (server still validates).
        $js = <<<'JS'
require(['jquery'], function($) {
    var map = __MAP__;
    var placeholder = __PLACEHOLDER__;
    var $trade = $('#id_elbytrade'), $level = $('#id_elbylevel');
    if (!$trade.length || !$level.length) { return; }
    function rebuild() {
        var cur = $level.val();
        var t = $trade.val();
        $level.empty().append($('<option>').attr('value', '').text(placeholder));
        if (t && map[t]) {
            $.each(map[t], function(val, name) {
                $level.append($('<option>').attr('value', val).text(name));
            });
        } else {
            $.each(map, function(tc, lv) {
                $.each(lv, function(val, name) {
                    if ($level.find('option').filter(function() { return this.value === val; }).length === 0) {
                        $level.append($('<option>').attr('value', val).text(name));
                    }
                });
            });
        }
        $level.val(cur);
    }
    $trade.on('change', rebuild);
    rebuild();
});
JS;
        $js = str_replace(
            ['__MAP__', '__PLACEHOLDER__'],
            [json_encode($map), json_encode(get_string('choosedots'))],
            $js
        );
        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Pre-fill the selects from stored metadata when editing an existing course.
     *
     * @param \core_course\hook\after_form_definition_after_data $hook
     */
    public static function definition_after_data(\core_course\hook\after_form_definition_after_data $hook): void {
        global $DB;

        if (self::eligible_type() === '') {
            return;
        }
        $mform = $hook->mform;
        if (!$mform->elementExists('elbytrade')) {
            return;
        }
        $courseid = $mform->getElementValue('id');
        if (is_array($courseid)) {
            $courseid = reset($courseid);
        }
        $courseid = (int) $courseid;
        if ($courseid <= 1) {
            return;
        }
        $meta = $DB->get_record('elby_course_meta', ['courseid' => $courseid],
            'trade_code, level, module_id, module_name');
        if (!$meta) {
            return;
        }
        if (!empty($meta->trade_code)) {
            $mform->getElement('elbytrade')->setValue($meta->trade_code);
        }
        if (!empty($meta->level) && $mform->elementExists('elbylevel')) {
            $mform->getElement('elbylevel')->setValue($meta->level);
        }
        if (!empty($meta->module_id) && $mform->elementExists('elbymodule')) {
            $label = ($meta->module_name !== null && $meta->module_name !== '')
                ? $meta->module_name : (string) $meta->module_id;
            $module = $mform->getElement('elbymodule');
            $module->addOption($label, $meta->module_id);
            $module->setValue($meta->module_id);
            if ($mform->elementExists('elbymodulename')) {
                $mform->getElement('elbymodulename')->setValue((string) $meta->module_name);
            }
        }
    }

    /**
     * Enforce the Trade + Level selects as required for teacher creators.
     *
     * @param \core_course\hook\after_form_validation $hook
     */
    public static function validation(\core_course\hook\after_form_validation $hook): void {
        if (self::eligible_type() === '') {
            return;
        }
        $data = $hook->get_data();
        if (!array_key_exists('elbytrade', $data)) {
            return; // Fields were not part of this form.
        }
        $errors = [];
        if (empty($data['elbytrade'])) {
            $errors['elbytrade'] = get_string('required');
        }
        if (empty($data['elbylevel'])) {
            $errors['elbylevel'] = get_string('required');
        }
        if ($errors) {
            $hook->add_errors($errors);
        }
    }

    /**
     * Persist the submitted Trade + Level to elby_course_meta.
     *
     * @param \core_course\hook\after_form_submission $hook
     */
    public static function submission(\core_course\hook\after_form_submission $hook): void {
        global $USER;

        if (!course_enricher::is_enabled()) {
            return;
        }
        $data = $hook->get_data();
        if (empty($data->elbytrade) || empty($data->id)) {
            return;
        }
        $override = [
            'trade_code' => (string) $data->elbytrade,
            'level' => isset($data->elbylevel) ? (string) $data->elbylevel : null,
            'source' => 'form',
        ];
        if (property_exists($data, 'elbymodule')) {
            $override['module_id'] = (string) $data->elbymodule;
            $override['module_name'] = isset($data->elbymodulename) ? (string) $data->elbymodulename : null;
        }
        if ($hook->isnewcourse) {
            $override['creator_userid'] = (int) $USER->id;
        }
        try {
            course_enricher::enrich_course((int) $data->id, $override);
        } catch (\Exception $e) {
            debugging('Course enrich (form) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
