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
 * External API for the RISE recruitment dashboard.
 *
 * Thin proxy over the RISE API: the browser calls these web service methods,
 * the server adds the API key and forwards the request. Responses are returned
 * verbatim as a JSON string (PARAM_RAW) because the upstream payload contains
 * dynamic, free-form keys (formResponses, rawFormData) that don't map to a
 * fixed external_single_structure.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_elby_dashboard\rise_client;
use local_elby_dashboard\tmis_client;
use context_system;

/**
 * External API for the RISE dashboard.
 */
class rise extends external_api {

    /**
     * Parameters for get_campaigns.
     */
    public static function get_campaigns_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get the list of RISE campaigns.
     *
     * @return string JSON-encoded campaigns payload.
     */
    public static function get_campaigns(): string {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $client = new rise_client();
        return json_encode($client->get_campaigns());
    }

    /**
     * Return value for get_campaigns.
     */
    public static function get_campaigns_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON-encoded campaigns payload');
    }

    /**
     * Parameters for get_applicants.
     */
    public static function get_applicants_parameters(): external_function_parameters {
        return new external_function_parameters([
            'campaignid' => new external_value(PARAM_ALPHANUM, 'Campaign id'),
            'status' => new external_value(PARAM_ALPHA, 'Status filter', VALUE_DEFAULT, ''),
            'provincecode' => new external_value(PARAM_ALPHANUM, 'Province code filter', VALUE_DEFAULT, ''),
            'district' => new external_value(PARAM_TEXT, 'District name filter', VALUE_DEFAULT, ''),
            'gender' => new external_value(PARAM_ALPHA, 'Gender filter', VALUE_DEFAULT, ''),
            'nesa' => new external_value(PARAM_ALPHANUMEXT, 'NESA review status filter', VALUE_DEFAULT, ''),
            'nida' => new external_value(PARAM_ALPHA, 'NIDA verification status filter', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, 'Free-text search (name/district)', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page number (1-based)', VALUE_DEFAULT, 1),
            'limit' => new external_value(PARAM_INT, 'Results per page', VALUE_DEFAULT, 20),
        ]);
    }


    /**
     * Get a paginated, filtered list of applicants for a campaign.
     *
     * @param string $campaignid Campaign id.
     * @param string $status Status filter.
     * @param string $provincecode Province code filter.
     * @param string $district District name filter.
     * @param string $gender Gender filter.
     * @param int $page Page number (1-based).
     * @param int $limit Results per page.
     * @return string JSON-encoded applicants payload.
     */
    public static function get_applicants(string $campaignid, string $status = '', string $provincecode = '',
            string $district = '', string $gender = '', string $nesa = '', string $nida = '',
            string $search = '', int $page = 1, int $limit = 20): string {
        global $DB;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $params = self::validate_parameters(self::get_applicants_parameters(), [
            'campaignid' => $campaignid,
            'status' => $status,
            'provincecode' => $provincecode,
            'district' => $district,
            'gender' => $gender,
            'nesa' => $nesa,
            'nida' => $nida,
            'search' => $search,
            'page' => $page,
            'limit' => $limit,
        ]);

        $page = max(1, $params['page']);
        $limit = min(100, max(1, $params['limit']));
        $remotefilters = [
            'status' => $params['status'],
            'provinceCode' => $params['provincecode'],
            'district' => $params['district'],
            'gender' => $params['gender'],
        ];
        $search = trim($params['search']);
        if ($search !== '') {
            // The RISE API searches name/NID/etc. via the `q` parameter.
            $remotefilters['q'] = $search;
        }

        // Cheap path: no NESA/NIDA review-status filter -> let the RISE API paginate (and
        // search, via `q`) directly across the full applicant set.
        if ($params['nesa'] === '' && $params['nida'] === '') {
            $client = new rise_client();
            return json_encode($client->get_applicants($params['campaignid'], $remotefilters + [
                'page' => $page,
                'limit' => $limit,
            ]));
        }

        // Review-filter path: NESA/NIDA decisions exist only in the dashboard DB, where every
        // review row also stores a snapshot of the RISE applicant. Serve straight from the DB
        // (applying any search across snapshot columns) so review filters never page through
        // the (slow, un-filterable) RISE applicants API.
        $where = ['campaignid = :campaignid'];
        $sqlparams = ['campaignid' => $params['campaignid']];
        if ($params['nesa'] !== '') {
            $where[] = 'nesastatus = :nesa';
            $sqlparams['nesa'] = $params['nesa'];
        }
        if ($params['nida'] !== '') {
            $where[] = 'nidstatus = :nida';
            $sqlparams['nida'] = $params['nida'];
        }
        if ($params['status'] !== '') {
            $where[] = 'applicantstatus = :astatus';
            $sqlparams['astatus'] = $params['status'];
        }
        if ($params['gender'] !== '') {
            $where[] = 'gender = :gender';
            $sqlparams['gender'] = $params['gender'];
        }
        if (trim($params['district']) !== '') {
            $where[] = 'district = :district';
            $sqlparams['district'] = $params['district'];
        }
        if (trim($params['search']) !== '') {
            $needle = '%' . $DB->sql_like_escape(trim($params['search'])) . '%';
            $parts = [];
            foreach (['fullname', 'district', 'phone', 'nid'] as $i => $col) {
                $pn = 'srch' . $i;
                $parts[] = $DB->sql_like($col, ':' . $pn, false);
                $sqlparams[$pn] = $needle;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $wheresql = implode(' AND ', $where);

        $total = (int) $DB->count_records_select('elby_rise_reviews', $wheresql, $sqlparams);
        $records = $DB->get_records_select('elby_rise_reviews', $wheresql, $sqlparams,
            'timemodified DESC', '*', ($page - 1) * $limit, $limit);

        $applicants = [];
        foreach ($records as $r) {
            $applicant = !empty($r->applicantdata) ? json_decode($r->applicantdata, true) : null;
            if (!is_array($applicant)) {
                $applicant = [
                    '_id' => $r->applicantid,
                    'fullName' => $r->fullname,
                    'gender' => $r->gender,
                    'phone' => $r->phone,
                    'district' => $r->district,
                    'nid' => $r->nid,
                    'status' => $r->applicantstatus,
                ];
            }
            $applicants[] = $applicant;
        }

        return json_encode([
            'applicants' => $applicants,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $limit)),
            ],
        ]);
    }

    /**
     * Return value for get_applicants.
     */
    public static function get_applicants_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON-encoded applicants payload');
    }

    /** @var string[] Allowed NESA review decisions. */
    private const NESA_STATUSES = ['approved', 'rejected', 'action_requested', 'pending'];

    /**
     * Parameters for get_reviews.
     */
    public static function get_reviews_parameters(): external_function_parameters {
        return new external_function_parameters([
            'campaignid' => new external_value(PARAM_ALPHANUM, 'Campaign id'),
        ]);
    }

    /**
     * Get all saved NESA reviews for a campaign, keyed by applicant id.
     *
     * @param string $campaignid Campaign id.
     * @return string JSON object: { applicantid: { nesastatus, nidverified, comment, ... } }.
     */
    public static function get_reviews(string $campaignid): string {
        global $DB;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $params = self::validate_parameters(self::get_reviews_parameters(), ['campaignid' => $campaignid]);

        $records = $DB->get_records('elby_rise_reviews', ['campaignid' => $params['campaignid']]);
        $out = [];
        foreach ($records as $r) {
            $out[$r->applicantid] = [
                'nesastatus' => $r->nesastatus,
                'nesaindexnumber' => $r->nesaindexnumber ?? '',
                'nidstatus' => $r->nidstatus ?? ((int) $r->nidverified === 1 ? 'verified' : 'pending'),
                'nidverified' => (int) $r->nidverified,
                'comment' => $r->comment,
                'reviewedby' => (int) $r->reviewedby,
                'timemodified' => (int) $r->timemodified,
            ];
        }
        return json_encode($out);
    }

    /**
     * Return value for get_reviews.
     */
    public static function get_reviews_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON object of reviews keyed by applicant id');
    }

    /**
     * Parameters for get_nesa_stats.
     */
    public static function get_nesa_stats_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get NESA review decision counts per campaign, aggregated across all applicants.
     *
     * @return string JSON object: { campaignid: { approved, rejected, action_requested, pending } }.
     */
    public static function get_nesa_stats(): string {
        global $DB;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $sql = "SELECT campaignid, nesastatus, COUNT(*) AS cnt
                  FROM {elby_rise_reviews}
              GROUP BY campaignid, nesastatus";
        $rs = $DB->get_recordset_sql($sql);
        $out = [];
        foreach ($rs as $row) {
            if (!isset($out[$row->campaignid])) {
                $out[$row->campaignid] = [
                    'approved' => 0, 'rejected' => 0, 'action_requested' => 0, 'pending' => 0,
                ];
            }
            if (array_key_exists($row->nesastatus, $out[$row->campaignid])) {
                $out[$row->campaignid][$row->nesastatus] = (int) $row->cnt;
            }
        }
        $rs->close();

        return json_encode((object) $out);
    }

    /**
     * Return value for get_nesa_stats.
     */
    public static function get_nesa_stats_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON object of NESA decision counts keyed by campaign id');
    }

    /**
     * Parameters for save_review.
     */
    public static function save_review_parameters(): external_function_parameters {
        return new external_function_parameters([
            'campaignid' => new external_value(PARAM_ALPHANUM, 'Campaign id'),
            'applicantid' => new external_value(PARAM_ALPHANUM, 'Applicant id'),
            'nesastatus' => new external_value(PARAM_ALPHANUMEXT, 'NESA decision'),
            'nesaindexnumber' => new external_value(PARAM_TEXT, 'NESA Senior 3 confirmation index number', VALUE_DEFAULT, ''),
            'nidverified' => new external_value(PARAM_BOOL, 'Whether the National ID is verified', VALUE_DEFAULT, false),
            'comment' => new external_value(PARAM_TEXT, 'Reviewer comment', VALUE_DEFAULT, ''),
            'applicantdata' => new external_value(PARAM_RAW, 'Full applicant JSON snapshot', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save (insert or update) a NESA eligibility review for a RISE learner.
     *
     * @param string $campaignid Campaign id.
     * @param string $applicantid Applicant id.
     * @param string $nesastatus NESA decision (approved|rejected|action_requested|pending).
     * @param bool $nidverified Whether the National ID is verified.
     * @param string $comment Reviewer comment.
     * @param string $applicantdata Full applicant JSON snapshot.
     * @return string JSON of the stored review.
     */
    public static function save_review(string $campaignid, string $applicantid, string $nesastatus,
            string $nesaindexnumber = '', bool $nidverified = false, string $comment = '',
            string $applicantdata = ''): string {
        global $DB, $USER;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $params = self::validate_parameters(self::save_review_parameters(), [
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
            'nesastatus' => $nesastatus,
            'nesaindexnumber' => $nesaindexnumber,
            'nidverified' => $nidverified,
            'comment' => $comment,
            'applicantdata' => $applicantdata,
        ]);

        if (!in_array($params['nesastatus'], self::NESA_STATUSES, true)) {
            throw new \invalid_parameter_exception('Invalid NESA status: ' . $params['nesastatus']);
        }
        if ($params['nesastatus'] === 'approved' && trim($params['nesaindexnumber']) === '') {
            throw new \invalid_parameter_exception('NESA index number is required for approved learners.');
        }
        if (in_array($params['nesastatus'], ['rejected', 'action_requested'], true) && trim($params['comment']) === '') {
            throw new \invalid_parameter_exception('Comment is required for rejected or action-requested learners.');
        }

        $indexnumber = trim($params['nesaindexnumber']);

        // Pull a few summary columns out of the snapshot for easy querying/reporting.
        $snapshot = json_decode($params['applicantdata'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $location = isset($snapshot['location']) && is_array($snapshot['location']) ? $snapshot['location'] : [];
        $now = time();

        $record = $DB->get_record('elby_rise_reviews', [
            'campaignid' => $params['campaignid'],
            'applicantid' => $params['applicantid'],
        ]);

        if ($indexnumber !== '') {
            $duplicateparams = ['indexnumber' => $indexnumber];
            $duplicatesql = 'nesaindexnumber = :indexnumber';
            if ($record) {
                $duplicatesql .= ' AND id <> :currentid';
                $duplicateparams['currentid'] = $record->id;
            }
            if ($DB->record_exists_select('elby_rise_reviews', $duplicatesql, $duplicateparams)) {
                throw new \invalid_parameter_exception('This NESA index number is already assigned to another learner.');
            }
        }

        $data = (object) [
            'campaignid' => $params['campaignid'],
            'applicantid' => $params['applicantid'],
            'fullname' => (string) ($snapshot['fullName'] ?? ''),
            'nid' => (string) ($snapshot['nid'] ?? ''),
            'gender' => (string) ($snapshot['gender'] ?? ''),
            'phone' => (string) ($snapshot['phone'] ?? ''),
            'district' => (string) ($location['districtName'] ?? $snapshot['district'] ?? ''),
            'applicantstatus' => (string) ($snapshot['status'] ?? ''),
            'applicantdata' => $params['applicantdata'] !== '' ? $params['applicantdata'] : null,
            'nesastatus' => $params['nesastatus'],
            'nesaindexnumber' => $indexnumber !== '' ? $indexnumber : null,
            'nidstatus' => $params['nidverified'] ? 'verified' : ($record->nidstatus ?? 'pending'),
            'nidverified' => $params['nidverified'] ? 1 : 0,
            'comment' => $params['comment'],
            'reviewedby' => $USER->id,
            'timemodified' => $now,
        ];

        if ($record) {
            $data->id = $record->id;
            // Don't blank an existing snapshot if none was sent this time.
            if ($data->applicantdata === null) {
                unset($data->applicantdata);
            }
            $DB->update_record('elby_rise_reviews', $data);
        } else {
            $data->timecreated = $now;
            $data->id = $DB->insert_record('elby_rise_reviews', $data);
        }

        return json_encode([
            'applicantid' => $data->applicantid,
            'nesastatus' => $data->nesastatus,
            'nesaindexnumber' => $data->nesaindexnumber ?? '',
            'nidstatus' => $data->nidstatus,
            'nidverified' => (int) ($params['nidverified'] ? 1 : 0),
            'comment' => $data->comment,
            'reviewedby' => (int) $USER->id,
            'timemodified' => $now,
        ]);
    }

    /**
     * Return value for save_review.
     */
    public static function save_review_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON of the stored review');
    }

    /**
     * Parameters for validate_nid.
     */
    public static function validate_nid_parameters(): external_function_parameters {
        return new external_function_parameters([
            'campaignid' => new external_value(PARAM_ALPHANUM, 'Campaign id'),
            'applicantid' => new external_value(PARAM_ALPHANUM, 'Applicant id'),
            'nid' => new external_value(PARAM_ALPHANUM, 'National ID to validate'),
            'fullname' => new external_value(PARAM_TEXT, 'Expected full name (from RISE)'),
            'dateofbirth' => new external_value(PARAM_TEXT, 'Expected date of birth (from RISE)', VALUE_DEFAULT, ''),
            'applicantdata' => new external_value(PARAM_RAW, 'Full applicant JSON snapshot', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Validate a learner's National ID against TMIS/NIDA and compare name + DOB.
     *
     * On a successful match the review row's nidverified flag is set.
     *
     * @param string $campaignid Campaign id.
     * @param string $applicantid Applicant id.
     * @param string $nid National ID.
     * @param string $fullname Expected full name from RISE.
     * @param string $dateofbirth Expected DOB from RISE.
     * @param string $applicantdata Full applicant JSON snapshot.
     * @return string JSON comparison result.
     */
    public static function validate_nid(string $campaignid, string $applicantid, string $nid,
            string $fullname, string $dateofbirth = '', string $applicantdata = ''): string {
        global $DB, $USER;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $params = self::validate_parameters(self::validate_nid_parameters(), [
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
            'nid' => $nid,
            'fullname' => $fullname,
            'dateofbirth' => $dateofbirth,
            'applicantdata' => $applicantdata,
        ]);

        $client = new tmis_client();
        $citizen = $client->get_citizen($params['nid']);

        // Unwrap a single envelope if the payload nests the record.
        $record = $citizen;
        foreach (['data', 'user', 'citizen', 'result'] as $wrap) {
            if (isset($record[$wrap]) && is_array($record[$wrap])) {
                $record = $record[$wrap];
                break;
            }
        }

        $citizenname = self::extract_citizen_name($citizen);
        $citizendob = self::extract_citizen_dob($citizen);

        $namematch = self::names_match($params['fullname'], $citizenname);
        $dobmatch = $params['dateofbirth'] === '' || $citizendob === ''
            ? null
            : (self::normalize_dob($params['dateofbirth']) === self::normalize_dob($citizendob));

        // Overall match: names must match, and DOB must match when both sides have a value.
        $match = $namematch && ($dobmatch !== false);

        self::set_nid_status($params['campaignid'], $params['applicantid'], $params['applicantdata'], $USER->id,
            $match ? 'verified' : 'mismatch');

        // Application-side values for the field-by-field comparison table.
        $snapshot = json_decode($params['applicantdata'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $appgender = (string) ($snapshot['gender'] ?? '');
        $citizengender = (string) ($record['gender'] ?? '');
        $gendermatch = ($appgender === '' || $citizengender === '') ? 'na'
            : (strcasecmp($appgender, $citizengender) === 0 ? 'match' : 'diff');

        $nationality = '';
        if (!empty($record['nationalityId'])) {
            $nationality = strtoupper($record['nationalityId']) === 'RW' ? 'Rwandan' : (string) $record['nationalityId'];
        }

        $fields = [
            ['field' => 'Name', 'app' => $params['fullname'], 'nida' => $citizenname,
                'status' => $namematch ? 'match' : 'diff'],
            ['field' => 'National ID', 'app' => $params['nid'], 'nida' => $params['nid'], 'status' => 'match'],
            ['field' => 'Date of birth', 'app' => $params['dateofbirth'], 'nida' => $citizendob,
                'status' => $dobmatch === null ? 'na' : ($dobmatch ? 'match' : 'diff')],
            ['field' => 'Gender', 'app' => $appgender, 'nida' => $citizengender, 'status' => $gendermatch],
        ];
        // NIDA-only enrichment fields (no application counterpart to compare).
        if (!empty($record['civilStatus'])) {
            $fields[] = ['field' => 'Civil status', 'app' => '', 'nida' => (string) $record['civilStatus'], 'status' => 'na'];
        }
        if ($nationality !== '') {
            $fields[] = ['field' => 'Nationality', 'app' => '', 'nida' => $nationality, 'status' => 'na'];
        }
        if (!empty($record['placeOfBirth'])) {
            $fields[] = ['field' => 'Place of birth', 'app' => '', 'nida' => (string) $record['placeOfBirth'], 'status' => 'na'];
        }

        return json_encode([
            'found' => true,
            'match' => $match,
            'namematch' => $namematch,
            'dobmatch' => $dobmatch,
            'fields' => $fields,
        ]);
    }

    /**
     * Return value for validate_nid.
     */
    public static function validate_nid_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON comparison result');
    }

    /**
     * Upsert a review row with the latest NIDA status, preserving any existing decision.
     *
     * @param string $campaignid Campaign id.
     * @param string $applicantid Applicant id.
     * @param string $applicantdata Applicant JSON snapshot.
     * @param int $userid Reviewer user id.
     * @param string $nidstatus NIDA status: verified or mismatch.
     */
    private static function set_nid_status(string $campaignid, string $applicantid,
            string $applicantdata, int $userid, string $nidstatus): void {
        global $DB;

        $nidstatus = $nidstatus === 'mismatch' ? 'mismatch' : 'verified';
        $now = time();
        $record = $DB->get_record('elby_rise_reviews', [
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
        ]);

        if ($record) {
            $DB->update_record('elby_rise_reviews', (object) [
                'id' => $record->id,
                'nidstatus' => $nidstatus,
                'nidverified' => $nidstatus === 'verified' ? 1 : 0,
                'timemodified' => $now,
            ]);
            return;
        }

        $snapshot = json_decode($applicantdata, true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $location = isset($snapshot['location']) && is_array($snapshot['location']) ? $snapshot['location'] : [];
        $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
            'fullname' => (string) ($snapshot['fullName'] ?? ''),
            'nid' => (string) ($snapshot['nid'] ?? ''),
            'gender' => (string) ($snapshot['gender'] ?? ''),
            'phone' => (string) ($snapshot['phone'] ?? ''),
            'district' => (string) ($location['districtName'] ?? $snapshot['district'] ?? ''),
            'applicantstatus' => (string) ($snapshot['status'] ?? ''),
            'applicantdata' => $applicantdata !== '' ? $applicantdata : null,
            'nesastatus' => 'pending',
            'nidstatus' => $nidstatus,
            'nidverified' => $nidstatus === 'verified' ? 1 : 0,
            'comment' => '',
            'reviewedby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Pull a citizen's name out of a TMIS payload, trying common field shapes.
     *
     * @param array $citizen Decoded TMIS payload.
     * @return string Best-effort full name.
     */
    private static function extract_citizen_name(array $citizen): string {
        // Unwrap a single 'data'/'user'/'citizen' envelope if present.
        foreach (['data', 'user', 'citizen', 'result'] as $wrap) {
            if (isset($citizen[$wrap]) && is_array($citizen[$wrap])) {
                $citizen = $citizen[$wrap];
                break;
            }
        }

        $get = function (array $keys) use ($citizen): string {
            foreach ($keys as $k) {
                if (!empty($citizen[$k]) && is_string($citizen[$k])) {
                    return $citizen[$k];
                }
            }
            return '';
        };

        $full = $get(['fullName', 'fullNames', 'names', 'name']);
        if ($full !== '') {
            return $full;
        }
        $fore = $get(['foreName', 'firstName', 'givenName', 'firstNames']);
        $sur = $get(['surname', 'lastName', 'familyName']);
        return trim($fore . ' ' . $sur);
    }

    /**
     * Pull a citizen's date of birth out of a TMIS payload.
     *
     * @param array $citizen Decoded TMIS payload.
     * @return string Best-effort DOB string.
     */
    private static function extract_citizen_dob(array $citizen): string {
        foreach (['data', 'user', 'citizen', 'result'] as $wrap) {
            if (isset($citizen[$wrap]) && is_array($citizen[$wrap])) {
                $citizen = $citizen[$wrap];
                break;
            }
        }
        foreach (['dateOfBirth', 'dob', 'birthDate', 'dateNaissance'] as $k) {
            if (!empty($citizen[$k]) && is_string($citizen[$k])) {
                return $citizen[$k];
            }
        }
        return '';
    }

    /**
     * Compare two names tolerantly (case/order/diacritics-insensitive, subset match).
     *
     * @param string $a First name.
     * @param string $b Second name.
     * @return bool True if they plausibly refer to the same person.
     */
    private static function names_match(string $a, string $b): bool {
        $tokens = function (string $s): array {
            $s = strtoupper(trim($s));
            // Strip accents to ASCII where possible.
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            $s = preg_replace('/[^A-Z ]+/', ' ', $s);
            $parts = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique($parts));
        };
        $ta = $tokens($a);
        $tb = $tokens($b);
        if (empty($ta) || empty($tb)) {
            return false;
        }
        // All tokens of the shorter set must appear in the longer set.
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        foreach ($short as $t) {
            if (!in_array($t, $long, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalize a date of birth to YYYY-MM-DD for comparison.
     *
     * @param string $value Raw date string.
     * @return string Normalized date, or the digits-only fallback.
     */
    private static function normalize_dob(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Drop any time component.
        $datepart = preg_split('/[T ]/', $value)[0];
        $ts = strtotime($datepart);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return preg_replace('/\D+/', '', $value);
    }
}
