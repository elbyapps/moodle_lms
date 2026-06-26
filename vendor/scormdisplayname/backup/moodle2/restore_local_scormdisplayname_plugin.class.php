<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Restores the local_scormdisplayname row attached to a SCORM activity backup.
 */
class restore_local_scormdisplayname_plugin extends restore_local_plugin {

    protected function define_module_plugin_structure() {
        if ($this->task->get_modulename() !== 'scorm') {
            return [];
        }
        return [
            new restore_path_element(
                'local_scormdisplayname',
                $this->get_pathfor('/local_scormdisplayname_wrapper/entry')
            ),
        ];
    }

    public function process_local_scormdisplayname($data): void {
        global $DB;
        $data = (object) $data;
        // Drop the source primary key; bind to the newly-restored scorm activity.
        unset($data->id);
        $data->scormid = $this->task->get_activityid();
        $data->timemodified = time();
        if ($DB->record_exists('local_scormdisplayname', ['scormid' => $data->scormid])) {
            // Existing override would be unusual at restore time; replace it.
            $DB->set_field('local_scormdisplayname', 'fulltitle', $data->fulltitle,
                ['scormid' => $data->scormid]);
        } else {
            $DB->insert_record('local_scormdisplayname', $data);
        }
    }
}
