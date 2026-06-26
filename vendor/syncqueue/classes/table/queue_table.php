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

namespace local_syncqueue\table;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for displaying sync queue items.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_table extends \table_sql {

    /** @var string Status filter */
    protected $statusfilter = '';

    /** @var string Event type filter */
    protected $eventtypefilter = '';

    /**
     * Constructor.
     *
     * @param string $uniqueid Unique ID for the table.
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        $this->define_columns([
            'id',
            'eventtype',
            'eventname',
            'status',
            'attempts',
            'timecreated',
            'timesynced',
            'lasterror',
            'actions',
        ]);

        $this->define_headers([
            get_string('id', 'local_syncqueue'),
            get_string('eventtype', 'local_syncqueue'),
            get_string('eventname', 'local_syncqueue'),
            get_string('status'),
            get_string('attempts', 'local_syncqueue'),
            get_string('timecreated', 'local_syncqueue'),
            get_string('timesynced', 'local_syncqueue'),
            get_string('lasterror', 'local_syncqueue'),
            get_string('actions'),
        ]);

        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('actions');
        $this->no_sorting('lasterror');

        $this->collapsible(false);
        $this->pageable(true);
    }

    /**
     * Set status filter.
     *
     * @param string $status Status to filter by.
     */
    public function set_status_filter(string $status): void {
        $this->statusfilter = $status;
    }

    /**
     * Set event type filter.
     *
     * @param string $eventtype Event type to filter by.
     */
    public function set_eventtype_filter(string $eventtype): void {
        $this->eventtypefilter = $eventtype;
    }

    /**
     * Query the database.
     *
     * @param int $pagesize Page size.
     * @param bool $useinitialsbar Whether to use initials bar.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;

        $where = '1=1';
        $params = [];

        if (!empty($this->statusfilter)) {
            $where .= ' AND status = :status';
            $params['status'] = $this->statusfilter;
        }

        if (!empty($this->eventtypefilter)) {
            $where .= ' AND eventtype = :eventtype';
            $params['eventtype'] = $this->eventtypefilter;
        }

        $sort = $this->get_sql_sort();
        if ($sort) {
            $sort = 'ORDER BY ' . $sort;
        }

        $totalcount = $DB->count_records_select('local_syncqueue_items', $where, $params);
        $this->pagesize($pagesize, $totalcount);

        $this->rawdata = $DB->get_records_select(
            'local_syncqueue_items',
            $where,
            $params,
            $sort ? str_replace('ORDER BY ', '', $sort) : '',
            '*',
            $this->get_page_start(),
            $this->get_page_size()
        );
    }

    /**
     * Render the ID column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_id($row) {
        return $row->id;
    }

    /**
     * Render the event type column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_eventtype($row) {
        $strkey = 'eventtype_' . $row->eventtype;
        $string = get_string_manager()->string_exists($strkey, 'local_syncqueue')
            ? get_string($strkey, 'local_syncqueue')
            : $row->eventtype;
        return $string;
    }

    /**
     * Render the event name column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_eventname($row) {
        return s($row->eventname);
    }

    /**
     * Render the status column with badge.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_status($row) {
        $badgeclass = [
            'pending' => 'badge-warning',
            'processing' => 'badge-info',
            'synced' => 'badge-success',
            'failed' => 'badge-danger',
            'conflict' => 'badge-secondary',
        ];

        $class = $badgeclass[$row->status] ?? 'badge-secondary';
        return \html_writer::tag('span',
            get_string('status_' . $row->status, 'local_syncqueue'),
            ['class' => 'badge ' . $class]
        );
    }

    /**
     * Render the attempts column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_attempts($row) {
        return $row->attempts;
    }

    /**
     * Render the time created column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_timecreated($row) {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Render the time synced column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_timesynced($row) {
        if (empty($row->timesynced)) {
            return '-';
        }
        return userdate($row->timesynced, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Render the last error column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_lasterror($row) {
        if (empty($row->lasterror)) {
            return '-';
        }
        // Truncate long errors.
        $error = s($row->lasterror);
        if (strlen($error) > 50) {
            return \html_writer::tag('span', substr($error, 0, 50) . '...', [
                'title' => $error,
                'data-toggle' => 'tooltip',
            ]);
        }
        return $error;
    }

    /**
     * Render the actions column.
     *
     * @param object $row Row data.
     * @return string Rendered content.
     */
    public function col_actions($row) {
        global $OUTPUT;

        $actions = [];

        // View payload button.
        $viewurl = new \moodle_url('/local/syncqueue/queue.php', [
            'action' => 'viewpayload',
            'id' => $row->id,
            'sesskey' => sesskey(),
        ]);
        $actions[] = \html_writer::link($viewurl, get_string('viewpayload', 'local_syncqueue'), ['class' => 'btn btn-sm btn-secondary']);

        // Retry button (only for failed items).
        if ($row->status === 'failed' || $row->status === 'conflict') {
            $retryurl = new \moodle_url('/local/syncqueue/queue.php', [
                'action' => 'retry',
                'id' => $row->id,
                'sesskey' => sesskey(),
            ]);
            $actions[] = \html_writer::link($retryurl, get_string('retry', 'local_syncqueue'), ['class' => 'btn btn-sm btn-primary']);
        }

        // Delete button (only for failed items).
        if ($row->status === 'failed' || $row->status === 'conflict') {
            $deleteurl = new \moodle_url('/local/syncqueue/queue.php', [
                'action' => 'delete',
                'id' => $row->id,
                'sesskey' => sesskey(),
            ]);
            $actions[] = \html_writer::link($deleteurl, get_string('delete'), ['class' => 'btn btn-sm btn-danger']);
        }

        return implode(' ', $actions);
    }
}
