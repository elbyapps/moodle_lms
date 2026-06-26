<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Adds the local_scormdisplayname row for a SCORM activity into the activity backup XML
 * so the longer "fulltitle" survives backup/restore.
 */
class backup_local_scormdisplayname_plugin extends backup_local_plugin {

    protected function define_module_plugin_structure() {
        if ($this->task->get_modulename() !== 'scorm') {
            return null;
        }

        $plugin = $this->get_plugin_element();

        $wrapper = new backup_nested_element('local_scormdisplayname_wrapper');
        $plugin->add_child($wrapper);

        $entry = new backup_nested_element('entry', ['id'], ['fulltitle', 'timemodified']);
        $wrapper->add_child($entry);

        $entry->set_source_table('local_scormdisplayname', ['scormid' => backup::VAR_PARENTID]);

        return $plugin;
    }
}
