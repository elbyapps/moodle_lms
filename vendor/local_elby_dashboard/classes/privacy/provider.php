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
 * Privacy provider for local_elby_dashboard.
 *
 * Covers the RISE learner provisioning data: review rows linked to a Moodle
 * user (NID, phone, applicant snapshot), deep-link tokens, SMS log entries,
 * learner correction submissions and their uploaded identity documents
 * (fileareas rise_idcard / rise_nesaresult).
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider implementation.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the personal data this plugin stores and sends.
     *
     * @param collection $collection The metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('elby_rise_reviews', [
            'userid' => 'privacy:metadata:reviews:userid',
            'campaignid' => 'privacy:metadata:reviews:campaignid',
            'applicantid' => 'privacy:metadata:reviews:applicantid',
            'fullname' => 'privacy:metadata:reviews:fullname',
            'nid' => 'privacy:metadata:reviews:nid',
            'phone' => 'privacy:metadata:reviews:phone',
            'applicantdata' => 'privacy:metadata:reviews:applicantdata',
            'nesastatus' => 'privacy:metadata:reviews:nesastatus',
            'comment' => 'privacy:metadata:reviews:comment',
            'reviewedby' => 'privacy:metadata:reviews:reviewedby',
        ], 'privacy:metadata:reviews');

        $collection->add_database_table('elby_rise_tokens', [
            'userid' => 'privacy:metadata:tokens:userid',
            'purpose' => 'privacy:metadata:tokens:purpose',
            'expires' => 'privacy:metadata:tokens:expires',
            'usedat' => 'privacy:metadata:tokens:usedat',
        ], 'privacy:metadata:tokens');

        $collection->add_database_table('elby_rise_corrections', [
            'campaignid' => 'privacy:metadata:reviews:campaignid',
            'applicantid' => 'privacy:metadata:reviews:applicantid',
            'firstname' => 'privacy:metadata:corrections:firstname',
            'lastname' => 'privacy:metadata:corrections:lastname',
            'nid' => 'privacy:metadata:corrections:nid',
            'note' => 'privacy:metadata:corrections:note',
            'reviewedby' => 'privacy:metadata:corrections:reviewedby',
        ], 'privacy:metadata:corrections');

        $collection->add_database_table('elby_rise_sms_log', [
            'userid' => 'privacy:metadata:smslog:userid',
            'phone' => 'privacy:metadata:smslog:phone',
            'message' => 'privacy:metadata:smslog:message',
            'status' => 'privacy:metadata:smslog:status',
            'error' => 'privacy:metadata:smslog:error',
        ], 'privacy:metadata:smslog');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');

        $collection->add_external_location_link('rise', [
            'linkeduserid' => 'privacy:metadata:rise:linkeduserid',
            'fullname' => 'privacy:metadata:rise:fullname',
            'nid' => 'privacy:metadata:rise:nid',
        ], 'privacy:metadata:rise');

        $collection->add_external_location_link('intouchsms', [
            'phone' => 'privacy:metadata:intouchsms:phone',
            'message' => 'privacy:metadata:intouchsms:message',
        ], 'privacy:metadata:intouchsms');

        return $collection;
    }

    /**
     * Contexts holding personal data for a user (all data lives in the system context).
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT c.id
                  FROM {context} c
                 WHERE c.contextlevel = :contextlevel
                   AND (EXISTS (SELECT 1 FROM {elby_rise_reviews} r
                                 WHERE r.userid = :userid1 OR r.reviewedby = :userid2)
                     OR EXISTS (SELECT 1 FROM {elby_rise_corrections} co WHERE co.reviewedby = :userid5)
                     OR EXISTS (SELECT 1 FROM {elby_rise_tokens} t WHERE t.userid = :userid3)
                     OR EXISTS (SELECT 1 FROM {elby_rise_sms_log} s WHERE s.userid = :userid4))";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_SYSTEM,
            'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid, 'userid4' => $userid,
            'userid5' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users with personal data in a context.
     *
     * @param userlist $userlist The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {elby_rise_reviews} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('reviewedby', 'SELECT reviewedby FROM {elby_rise_reviews} WHERE reviewedby IS NOT NULL', []);
        $userlist->add_from_sql('reviewedby', 'SELECT reviewedby FROM {elby_rise_corrections} WHERE reviewedby IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {elby_rise_tokens} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {elby_rise_sms_log} WHERE userid IS NOT NULL', []);
    }

    /**
     * Export personal data for a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        $syscontext = \context_system::instance();
        if (!in_array($syscontext->id, $contextlist->get_contextids())) {
            return;
        }

        $subcontext = [get_string('rise', 'local_elby_dashboard')];

        $reviews = $DB->get_records('elby_rise_reviews', ['userid' => $userid]);
        foreach ($reviews as $review) {
            $path = array_merge($subcontext, ['review-' . $review->id]);
            writer::with_context($syscontext)->export_data($path, (object) [
                'campaignid' => $review->campaignid,
                'applicantid' => $review->applicantid,
                'fullname' => $review->fullname,
                'nid' => $review->nid,
                'phone' => $review->phone,
                'nesastatus' => $review->nesastatus,
                'nidstatus' => $review->nidstatus,
                'comment' => $review->comment,
                'provisioningaction' => $review->provisioningaction,
                'correctionstatus' => $review->correctionstatus,
                'timemodified' => transform::datetime($review->timemodified),
            ]);

            $corrections = $DB->get_records('elby_rise_corrections', [
                'campaignid' => $review->campaignid,
                'applicantid' => $review->applicantid,
            ]);
            foreach ($corrections as $correction) {
                $cpath = array_merge($path, ['correction-' . $correction->id]);
                writer::with_context($syscontext)->export_data($cpath, (object) [
                    'firstname' => $correction->firstname,
                    'lastname' => $correction->lastname,
                    'nid' => $correction->nid,
                    'note' => $correction->note,
                    'status' => $correction->status,
                    'timecreated' => transform::datetime($correction->timecreated),
                ]);
                writer::with_context($syscontext)->export_area_files($cpath, 'local_elby_dashboard',
                    'rise_idcard', $correction->id);
                writer::with_context($syscontext)->export_area_files($cpath, 'local_elby_dashboard',
                    'rise_nesaresult', $correction->id);
            }
        }

        // Reviews this user performed as the NESA reviewer (declared via reviewedby).
        $performed = $DB->get_records('elby_rise_reviews', ['reviewedby' => $userid]);
        if ($performed) {
            writer::with_context($syscontext)->export_data(array_merge($subcontext, ['reviews-performed']), (object) [
                'reviews' => array_values(array_map(function ($review) {
                    return [
                        'campaignid' => $review->campaignid,
                        'applicantid' => $review->applicantid,
                        'nesastatus' => $review->nesastatus,
                        'comment' => $review->comment,
                        'timemodified' => transform::datetime($review->timemodified),
                    ];
                }, $performed)),
            ]);
        }

        // Corrections this user cleared as the reviewer (declared via reviewedby).
        $cleared = $DB->get_records('elby_rise_corrections', ['reviewedby' => $userid]);
        if ($cleared) {
            writer::with_context($syscontext)->export_data(array_merge($subcontext, ['corrections-reviewed']), (object) [
                'corrections' => array_values(array_map(function ($correction) {
                    return [
                        'campaignid' => $correction->campaignid,
                        'applicantid' => $correction->applicantid,
                        'status' => $correction->status,
                        'reviewedat' => transform::datetime($correction->reviewedat),
                    ];
                }, $cleared)),
            ]);
        }

        // SMS logs: rows attributed to the user directly, plus pre-account rows
        // (userid = null) belonging to the applicants of their linked reviews.
        $smslogs = $DB->get_records_sql(
            "SELECT l.*
               FROM {elby_rise_sms_log} l
              WHERE l.userid = :userid
                 OR EXISTS (SELECT 1
                              FROM {elby_rise_reviews} r
                             WHERE r.userid = :userid2
                               AND r.campaignid = l.campaignid
                               AND r.applicantid = l.applicantid)",
            ['userid' => $userid, 'userid2' => $userid]);
        if ($smslogs) {
            writer::with_context($syscontext)->export_data(array_merge($subcontext, ['sms']), (object) [
                'messages' => array_values(array_map(function ($log) {
                    return [
                        'phone' => $log->phone,
                        'purpose' => $log->purpose,
                        'message' => $log->message,
                        'status' => $log->status,
                        // May contain the recipient phone (e.g. "Invalid ... number: <phone>").
                        'error' => $log->error,
                        'timecreated' => transform::datetime($log->timecreated),
                    ];
                }, $smslogs)),
            ]);
        }

        // Deep-link tokens: issued for the user directly, or for the applicants of
        // their linked reviews. Only non-secret fields are exported (never the hash).
        $tokens = $DB->get_records_sql(
            "SELECT t.id, t.purpose, t.expires, t.usedat, t.timecreated
               FROM {elby_rise_tokens} t
              WHERE t.userid = :userid
                 OR EXISTS (SELECT 1
                              FROM {elby_rise_reviews} r
                             WHERE r.userid = :userid2
                               AND r.campaignid = t.campaignid
                               AND r.applicantid = t.applicantid)",
            ['userid' => $userid, 'userid2' => $userid]);
        if ($tokens) {
            writer::with_context($syscontext)->export_data(array_merge($subcontext, ['tokens']), (object) [
                'tokens' => array_values(array_map(function ($token) {
                    return [
                        'purpose' => $token->purpose,
                        'expires' => transform::datetime($token->expires),
                        'used' => $token->usedat ? transform::datetime($token->usedat) : '',
                        'timecreated' => transform::datetime($token->timecreated),
                    ];
                }, $tokens)),
            ]);
        }
    }

    /**
     * Delete all personal data in a context (system context wipes the RISE learner data).
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_elby_dashboard', 'rise_idcard');
        $fs->delete_area_files($context->id, 'local_elby_dashboard', 'rise_nesaresult');
        $DB->delete_records('elby_rise_corrections');
        $DB->delete_records('elby_rise_tokens');
        $DB->delete_records('elby_rise_sms_log');
        $DB->delete_records('elby_rise_reviews');
    }

    /**
     * Delete personal data for a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::delete_user($userid);
            }
        }
    }

    /**
     * Delete personal data for multiple users in a context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user((int) $userid);
        }
    }

    /**
     * Delete one user's RISE data: tokens, SMS logs, corrections + uploaded
     * evidence for their linked reviews. The review row is kept as the NESA
     * decision audit record but every personal field on it is erased —
     * name, NID, phone, snapshot, index number, comment and notification state.
     *
     * The external RISE applicant id could re-identify the learner through the
     * RISE platform, so it is replaced with an opaque per-row value. Retention
     * basis for the remaining fields: campaignid + nesastatus + timestamps are
     * kept solely for aggregate campaign statistics and identify no one.
     *
     * @param int $userid User id.
     */
    private static function delete_user(int $userid): void {
        global $DB;

        $fs = get_file_storage();
        $syscontext = \context_system::instance();

        $reviews = $DB->get_records('elby_rise_reviews', ['userid' => $userid]);
        foreach ($reviews as $review) {
            $corrections = $DB->get_records('elby_rise_corrections', [
                'campaignid' => $review->campaignid,
                'applicantid' => $review->applicantid,
            ]);
            foreach ($corrections as $correction) {
                $fs->delete_area_files($syscontext->id, 'local_elby_dashboard', 'rise_idcard', $correction->id);
                $fs->delete_area_files($syscontext->id, 'local_elby_dashboard', 'rise_nesaresult', $correction->id);
            }
            $DB->delete_records('elby_rise_corrections', [
                'campaignid' => $review->campaignid,
                'applicantid' => $review->applicantid,
            ]);
            $DB->delete_records('elby_rise_tokens', [
                'campaignid' => $review->campaignid,
                'applicantid' => $review->applicantid,
            ]);
            // SMS sent BEFORE the account existed carry userid = null but still
            // hold the learner's phone/message — erase them by applicant too.
            $DB->delete_records('elby_rise_sms_log', [
                'campaignid' => $review->campaignid,
                'applicantid' => $review->applicantid,
            ]);
            $DB->update_record('elby_rise_reviews', (object) [
                'id' => $review->id,
                'userid' => null,
                // Break the link to the external RISE record (unique per row so
                // the campaign+applicant unique key holds).
                'applicantid' => 'anon' . $review->id,
                'fullname' => null,
                'nid' => null,
                'gender' => null,
                'phone' => null,
                'district' => null,
                'applicantstatus' => null,
                'applicantdata' => null,
                'nesaindexnumber' => null,
                'comment' => null,
                'lastnotifiedhash' => null,
                'lastnotifiedat' => 0,
                // RISE sync state carries linked-user identifiers (the Moodle userid
                // in riselinkeduserid, ids inside error text) — erase it too.
                'riselinkeduserid' => null,
                'risesyncerror' => null,
                'risesyncstatus' => null,
                'risesyncedat' => 0,
                'timemodified' => time(),
            ]);
        }

        $DB->delete_records('elby_rise_tokens', ['userid' => $userid]);
        $DB->delete_records('elby_rise_sms_log', ['userid' => $userid]);

        // The user may also appear as the NESA reviewer — detach their identity
        // from reviews and cleared corrections (the records themselves remain).
        $DB->set_field_select('elby_rise_reviews', 'reviewedby', null,
            'reviewedby = :userid', ['userid' => $userid]);
        $DB->set_field_select('elby_rise_corrections', 'reviewedby', null,
            'reviewedby = :userid', ['userid' => $userid]);
    }
}
