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

namespace local_elby_dashboard;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the RISE learner provisioning service.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_elby_dashboard\rise_user_service
 */
final class rise_user_service_test extends advanced_testcase {

    /**
     * Build a service whose RISE client returns a fixed applicant and records PATCHes.
     *
     * @param array $applicant Applicant payload returned by get_applicant().
     * @param array $patchresponse Response for patch_applicant().
     * @return array{0: rise_user_service, 1: object} Service and a recorder with ->patches.
     */
    private function make_service(array $applicant, array $patchresponse = ['success' => true]): array {
        $recorder = new \stdClass();
        $recorder->patches = [];

        $client = new class($applicant, $patchresponse, $recorder) extends rise_client {
            /** @var array */
            private array $applicant;
            /** @var array */
            private array $patchresponse;
            /** @var object */
            private object $recorder;

            public function __construct(array $applicant, array $patchresponse, object $recorder) {
                // Deliberately no parent::__construct(): tests must not need API config.
                $this->applicant = $applicant;
                $this->patchresponse = $patchresponse;
                $this->recorder = $recorder;
            }

            public function get_applicant(string $applicantid): array {
                return $this->applicant;
            }

            public function patch_applicant(string $applicantid, array $fields): array {
                $this->recorder->patches[] = ['id' => $applicantid, 'fields' => $fields];
                return $this->patchresponse;
            }
        };

        // TMIS client that always fails => server-side NIDA check stays inconclusive.
        $tmis = new class extends tmis_client {
            public function __construct() {
            }

            public function get_citizen(string $nid): array {
                throw new \moodle_exception('tmiserror', 'local_elby_dashboard');
            }
        };

        return [new rise_user_service($client, null, $tmis), $recorder];
    }

    /**
     * A minimal RISE applicant payload.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    private function applicant(array $overrides = []): array {
        return array_merge([
            '_id' => 'app1',
            'campaignId' => 'camp1',
            'fullName' => 'NIYONZIMA Bruno Aman',
            'nid' => '1200680012345678',
            'phone' => '0788123456',
            'gender' => 'Male',
            'status' => 'ENROLLED',
        ], $overrides);
    }

    /**
     * Insert an approved review row — provisioning requires one (the approval
     * state is re-checked inside the provisioning lock).
     *
     * @param string $campaignid Campaign id.
     * @param string $applicantid Applicant id.
     * @return int Review id.
     */
    private function approve(string $campaignid = 'camp1', string $applicantid = 'app1'): int {
        global $DB;
        return $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => $campaignid, 'applicantid' => $applicantid, 'nesastatus' => 'approved',
            'nidstatus' => 'pending', 'nidverified' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    public function test_split_name(): void {
        $this->assertSame(['firstname' => 'Bruno Aman', 'lastname' => 'Niyonzima'],
            rise_user_service::split_name('NIYONZIMA BRUNO AMAN'));
        $this->assertSame(['firstname' => '', 'lastname' => 'Mukamana'],
            rise_user_service::split_name('MUKAMANA'));
        $this->assertSame(['firstname' => '', 'lastname' => ''],
            rise_user_service::split_name('   '));
    }

    public function test_is_valid_nid(): void {
        $this->assertTrue(rise_user_service::is_valid_nid('1200680012345678'));
        $this->assertFalse(rise_user_service::is_valid_nid(''));
        $this->assertFalse(rise_user_service::is_valid_nid('12006800123'));
        $this->assertFalse(rise_user_service::is_valid_nid('12006800123456789'));
        $this->assertFalse(rise_user_service::is_valid_nid('12OO680012345678'));
    }

    public function test_evaluate_action(): void {
        $service = new rise_user_service();
        $this->assertSame('nid_missing', $service->evaluate_action(['nid' => ''], null));
        $this->assertSame('nid_invalid', $service->evaluate_action(['nid' => '123'], null));
        $this->assertSame('ok', $service->evaluate_action(['nid' => '1200680012345678'], null));
        $mismatch = (object) ['nidstatus' => 'mismatch'];
        $this->assertSame('details_mismatch', $service->evaluate_action(['nid' => '1200680012345678'], $mismatch));
    }

    public function test_next_username_sequential_and_skips_taken(): void {
        global $DB;
        $this->resetAfterTest();

        $prefix = '1' . substr(date('Y'), 2, 2);
        $service = new rise_user_service();

        $first = $service->next_username('learner');
        $this->assertSame($prefix . '00001', $first);

        // Occupy the next number with a legacy manually-created account.
        $this->getDataGenerator()->create_user(['username' => $prefix . '00002']);
        $second = $service->next_username('learner');
        $this->assertSame($prefix . '00003', $second);

        $this->assertSame(4, (int) $DB->get_field('elby_rise_username_seq', 'nextval', ['seqkey' => $prefix]));
    }

    public function test_provision_creates_user_with_nid_idnumber(): void {
        global $DB;
        $this->resetAfterTest();

        $this->approve();
        [$service, $recorder] = $this->make_service($this->applicant());
        $result = $service->provision('camp1', 'app1');

        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['created']);
        $user = $DB->get_record('user', ['id' => $result['userid']], '*', MUST_EXIST);
        $this->assertSame('1200680012345678', $user->idnumber);
        $this->assertSame('Niyonzima', $user->lastname);
        $this->assertSame('Bruno Aman', $user->firstname);
        $this->assertSame('manual', $user->auth);
        $this->assertSame(1, (int) $user->confirmed);
        $this->assertStringEndsWith('@learner.rise.reb.rw', $user->email);
        $this->assertSame(1, (int) get_user_preferences('auth_forcepasswordchange', 0, $user->id));

        $review = $DB->get_record('elby_rise_reviews', ['campaignid' => 'camp1', 'applicantid' => 'app1'], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $review->userid);
        $this->assertSame('ok', $review->provisioningaction);
        $this->assertSame('ok', $review->risesyncstatus);

        // The Moodle userid was PATCHed back to RISE.
        $this->assertCount(1, $recorder->patches);
        $this->assertSame(['linkedUserId' => (string) $user->id], $recorder->patches[0]['fields']);

        // A welcome SMS was attempted (skipped: gateway not configured) and logged.
        $this->assertTrue($DB->record_exists('elby_rise_sms_log',
            ['applicantid' => 'app1', 'purpose' => 'welcome', 'status' => 'skipped']));
    }

    public function test_provision_links_existing_user_by_nid(): void {
        global $DB;
        $this->resetAfterTest();

        // A RISE-shaped legacy account: manual auth + 8-digit {type}{yy}{seq} username.
        $existing = $this->getDataGenerator()->create_user([
            'username' => '12609278',
            'auth' => 'manual',
            'idnumber' => '1200680012345678',
            'firstname' => 'Bruno Aman',
            'lastname' => 'Niyonzima',
        ]);
        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $result = $service->provision('camp1', 'app1');

        $this->assertFalse($result['created']);
        $this->assertSame((int) $existing->id, $result['userid']);
        $review = $DB->get_record('elby_rise_reviews', ['campaignid' => 'camp1', 'applicantid' => 'app1'], '*', MUST_EXIST);
        $this->assertSame((int) $existing->id, (int) $review->userid);
    }

    public function test_provision_never_links_non_rise_account(): void {
        global $DB;
        $this->resetAfterTest();

        // A staff/SDMS-style account holding the NID in idnumber must never be
        // linked (and thus never rewritten by refresh_linked_user).
        $staff = $this->getDataGenerator()->create_user([
            'username' => 'staffmember1',
            'idnumber' => '1200680012345678',
        ]);
        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $result = $service->provision('camp1', 'app1');

        $this->assertTrue($result['blocked']);
        $this->assertSame('duplicate_nid', $result['action']);
        $fresh = $DB->get_record('user', ['id' => $staff->id], '*', MUST_EXIST);
        $this->assertSame('staffmember1', $fresh->username);
        $this->assertSame($staff->firstname, $fresh->firstname);
    }

    public function test_provision_blocks_on_duplicate_nid(): void {
        global $DB;
        $this->resetAfterTest();

        $this->getDataGenerator()->create_user(['idnumber' => '1200680012345678']);
        $this->getDataGenerator()->create_user(['idnumber' => '1200680012345678']);

        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $result = $service->provision('camp1', 'app1');

        $this->assertTrue($result['blocked']);
        $this->assertSame('duplicate_nid', $result['action']);
        $review = $DB->get_record('elby_rise_reviews', ['campaignid' => 'camp1', 'applicantid' => 'app1'], '*', MUST_EXIST);
        $this->assertEmpty($review->userid);
        $this->assertSame('duplicate_nid', $review->provisioningaction);
    }

    public function test_provision_blocks_when_user_linked_to_other_applicant(): void {
        global $DB;
        $this->resetAfterTest();

        $existing = $this->getDataGenerator()->create_user([
            'username' => '12609001', 'auth' => 'manual', 'idnumber' => '1200680012345678',
        ]);
        $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'otherapp', 'nesastatus' => 'approved',
            'nidstatus' => 'pending', 'nidverified' => 0, 'userid' => $existing->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $result = $service->provision('camp1', 'app1');

        $this->assertTrue($result['blocked']);
        $this->assertSame('duplicate_nid', $result['action']);
    }

    public function test_provision_fails_closed_on_campaign_mismatch(): void {
        $this->resetAfterTest();

        [$service] = $this->make_service($this->applicant(['campaignId' => 'someothercampaign']));
        $this->expectException(\moodle_exception::class);
        $service->provision('camp1', 'app1');
    }

    public function test_provision_fails_closed_on_missing_campaign(): void {
        $this->resetAfterTest();

        // A response without a campaign id cannot prove it belongs to the request.
        [$service] = $this->make_service($this->applicant(['campaignId' => '']));
        $this->expectException(\moodle_exception::class);
        $service->provision('camp1', 'app1');
    }

    public function test_provision_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $first = $service->provision('camp1', 'app1');
        $second = $service->provision('camp1', 'app1');

        $this->assertSame($first['userid'], $second['userid']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, $DB->count_records('user', ['idnumber' => '1200680012345678', 'deleted' => 0]));
    }

    public function test_provision_creates_account_despite_invalid_nid(): void {
        global $DB;
        $this->resetAfterTest();

        $this->approve();
        [$service] = $this->make_service($this->applicant(['nid' => '123']));
        $result = $service->provision('camp1', 'app1');

        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['created']);
        $this->assertSame('nid_invalid', $result['action']);
        $user = $DB->get_record('user', ['id' => $result['userid']], '*', MUST_EXIST);
        $this->assertSame('123', $user->idnumber);

        // An action-needed SMS attempt is logged too (skipped: gateway unconfigured).
        $this->assertTrue($DB->record_exists('elby_rise_sms_log',
            ['applicantid' => 'app1', 'purpose' => 'action']));
    }

    public function test_provision_records_conflict_when_rise_reports_other_link(): void {
        global $DB;
        $this->resetAfterTest();

        $this->approve();
        [$service] = $this->make_service($this->applicant(['linkedUserId' => '999999']));
        $result = $service->provision('camp1', 'app1');

        $this->assertSame('conflict', $result['risesync']);
        $review = $DB->get_record('elby_rise_reviews', ['campaignid' => 'camp1', 'applicantid' => 'app1'], '*', MUST_EXIST);
        $this->assertSame('conflict', $review->risesyncstatus);
        $this->assertSame('999999', $review->riselinkeduserid);
    }

    public function test_provision_blocks_when_not_approved(): void {
        global $DB;
        $this->resetAfterTest();

        [$service] = $this->make_service($this->applicant());

        // No review row at all: nothing is created, no row appears as a side effect.
        $result = $service->provision('camp1', 'app1');
        $this->assertTrue($result['blocked']);
        $this->assertSame('not_approved', $result['blockedreason']);
        $this->assertSame(0, $DB->count_records('user', ['idnumber' => '1200680012345678', 'deleted' => 0]));
        $this->assertFalse($DB->record_exists('elby_rise_reviews', ['campaignid' => 'camp1', 'applicantid' => 'app1']));

        // A concurrent save flipped the decision after the caller's check: the
        // in-lock re-check refuses to create/link.
        $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'rejected',
            'nidstatus' => 'pending', 'nidverified' => 0, 'comment' => 'no',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $result = $service->provision('camp1', 'app1');
        $this->assertTrue($result['blocked']);
        $this->assertSame('not_approved', $result['blockedreason']);
        $this->assertSame(0, $DB->count_records('user', ['idnumber' => '1200680012345678', 'deleted' => 0]));
    }

    public function test_linked_review_flags_duplicate_when_nid_moves_to_taken_nid(): void {
        global $DB;
        $this->resetAfterTest();

        // Learner A is provisioned and linked with their original NID.
        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $first = $service->provision('camp1', 'app1');
        $linkeduser = $DB->get_record('user', ['id' => $first['userid']], '*', MUST_EXIST);

        // Another active user already owns the NID the applicant corrects to.
        $othernid = '1200680087654321';
        $this->getDataGenerator()->create_user(['idnumber' => $othernid]);

        // Re-provision with the corrected (now conflicting) RISE NID.
        [$service2] = $this->make_service($this->applicant(['nid' => $othernid]));
        $second = $service2->provision('camp1', 'app1');

        $this->assertSame('duplicate_nid', $second['action']);
        $review = $DB->get_record('elby_rise_reviews', ['campaignid' => 'camp1', 'applicantid' => 'app1'], '*', MUST_EXIST);
        $this->assertSame('duplicate_nid', $review->provisioningaction);
        // Still linked to the original account, whose idnumber was never moved.
        $this->assertSame((int) $linkeduser->id, (int) $review->userid);
        $fresh = $DB->get_record('user', ['id' => $linkeduser->id], '*', MUST_EXIST);
        $this->assertSame('1200680012345678', $fresh->idnumber);
    }

    public function test_status_for_resolves_unreviewed_learner_by_nid(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user([
            'username' => '12609100', 'auth' => 'manual', 'idnumber' => '1200680012345678',
        ]);
        $service = new rise_user_service();
        $status = $service->status_for([
            ['applicantid' => 'unreviewed1', 'nid' => '1200680012345678'],
            ['applicantid' => 'unreviewed2', 'nid' => ''],
        ], 'camp1');

        $this->assertTrue($status['unreviewed1']['hasaccount']);
        $this->assertSame((int) $user->id, $status['unreviewed1']['userid']);
        $this->assertFalse($status['unreviewed1']['linked']);
        $this->assertFalse($status['unreviewed2']['hasaccount']);
        // Missing state normalizes to the planned 'none', not ''.
        $this->assertSame('none', $status['unreviewed1']['provisioningaction']);
        $this->assertSame('none', $status['unreviewed1']['correctionstatus']);
    }

    public function test_status_for_surfaces_duplicate_nid_before_create(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_user(['idnumber' => '1200680012345678']);
        $this->getDataGenerator()->create_user(['idnumber' => '1200680012345678']);
        $service = new rise_user_service();
        $status = $service->status_for([
            ['applicantid' => 'dupapp', 'nid' => '1200680012345678'],
        ], 'camp1');

        $this->assertFalse($status['dupapp']['hasaccount']);
        $this->assertSame('duplicate_nid', $status['dupapp']['provisioningaction']);
    }

    public function test_status_for_surfaces_single_match_conflicts(): void {
        global $DB;
        $this->resetAfterTest();

        // Case 1: the only NID match is a non-RISE-shaped (e.g. staff) account.
        $this->getDataGenerator()->create_user([
            'username' => 'staffmember2', 'idnumber' => '1200680011111111',
        ]);
        // Case 2: the only NID match is a RISE-shaped account already linked to
        // another applicant.
        $linked = $this->getDataGenerator()->create_user([
            'username' => '12609200', 'auth' => 'manual', 'idnumber' => '1200680022222222',
        ]);
        $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'otherapp', 'nesastatus' => 'approved',
            'nidstatus' => 'pending', 'nidverified' => 0, 'userid' => $linked->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $service = new rise_user_service();
        $status = $service->status_for([
            ['applicantid' => 'staffnid', 'nid' => '1200680011111111'],
            ['applicantid' => 'stolennid', 'nid' => '1200680022222222'],
        ], 'camp1');

        $this->assertFalse($status['staffnid']['hasaccount']);
        $this->assertSame('duplicate_nid', $status['staffnid']['provisioningaction']);
        $this->assertFalse($status['stolennid']['hasaccount']);
        $this->assertSame('duplicate_nid', $status['stolennid']['provisioningaction']);
    }

    public function test_notify_and_sync_reject_campaign_mismatch(): void {
        global $DB;
        $this->resetAfterTest();

        // The fake client returns an applicant from a different campaign.
        [$service] = $this->make_service($this->applicant(['campaignId' => 'othercampaign']));

        $reviewid = $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'action_requested',
            'nidstatus' => 'pending', 'nidverified' => 0, 'comment' => 'Fix your NID',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // notify_learner treats the invalid response as a fetch failure: nothing
        // is sent, nothing is marked notified, and the skip is logged.
        $service->notify_learner($review);
        $this->assertTrue($DB->record_exists('elby_rise_sms_log',
            ['applicantid' => 'app1', 'purpose' => 'action', 'status' => 'skipped']));
        $this->assertEmpty($DB->get_field('elby_rise_reviews', 'lastnotifiedhash', ['id' => $reviewid]));

        // retry_rise_sync fails closed outright.
        $DB->update_record('elby_rise_reviews', (object) [
            'id' => $reviewid,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
        $this->expectException(\moodle_exception::class);
        $service->retry_rise_sync('camp1', 'app1');
    }

    public function test_privacy_delete_erases_preaccount_sms_logs_and_sync_state(): void {
        global $DB;
        $this->resetAfterTest();

        $learner = $this->getDataGenerator()->create_user();
        $reviewid = $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'approved',
            'nidstatus' => 'pending', 'nidverified' => 0, 'userid' => $learner->id,
            'fullname' => 'PRIV Learner', 'nid' => '1200680012345678', 'phone' => '0788123456',
            'riselinkeduserid' => (string) $learner->id, 'risesyncstatus' => 'conflict',
            'risesyncerror' => 'RISE already reports linkedUserId=' . $learner->id, 'risesyncedat' => time(),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        // Pre-account SMS: userid was null when the notification went out.
        $DB->insert_record('elby_rise_sms_log', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'userid' => null,
            'phone' => '0788123456', 'purpose' => 'action', 'message' => 'x',
            'status' => 'sent', 'timecreated' => time(),
        ]);

        $method = new \ReflectionMethod(\local_elby_dashboard\privacy\provider::class, 'delete_user');
        $method->setAccessible(true);
        $method->invoke(null, (int) $learner->id);

        $this->assertSame(0, $DB->count_records('elby_rise_sms_log',
            ['campaignid' => 'camp1', 'applicantid' => 'app1']));

        // Personal fields AND RISE sync identifiers are erased; the decision stays.
        $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], '*', MUST_EXIST);
        $this->assertNull($review->userid);
        $this->assertNull($review->fullname);
        $this->assertNull($review->nid);
        $this->assertNull($review->phone);
        $this->assertNull($review->riselinkeduserid);
        $this->assertNull($review->risesyncerror);
        $this->assertNull($review->risesyncstatus);
        $this->assertSame(0, (int) $review->risesyncedat);
        $this->assertSame('approved', $review->nesastatus);
        // The external RISE applicant id is replaced with an opaque value so the
        // learner cannot be re-identified through the RISE platform.
        $this->assertSame('anon' . $reviewid, $review->applicantid);
    }

    public function test_sms_normalise_rw(): void {
        $this->assertSame('0788123456', sms_client::normalise_rw('0788123456'));
        $this->assertSame('0788123456', sms_client::normalise_rw('+250 788 123 456'));
        $this->assertSame('0788123456', sms_client::normalise_rw('250788123456'));
        $this->assertSame('0788123456', sms_client::normalise_rw('788123456'));
        $this->assertNull(sms_client::normalise_rw('12345'));
        $this->assertNull(sms_client::normalise_rw(''));
        $this->assertNull(sms_client::normalise_rw('0688123456'));
    }

    public function test_privacy_delete_detaches_reviewer_identity(): void {
        global $DB;
        $this->resetAfterTest();

        $reviewer = $this->getDataGenerator()->create_user();
        $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'approved',
            'nidstatus' => 'pending', 'nidverified' => 0, 'reviewedby' => $reviewer->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('elby_rise_corrections', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'firstname' => 'A', 'lastname' => 'B',
            'status' => 'reviewed', 'reviewedby' => $reviewer->id, 'reviewedat' => time(),
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $method = new \ReflectionMethod(\local_elby_dashboard\privacy\provider::class, 'delete_user');
        $method->setAccessible(true);
        $method->invoke(null, (int) $reviewer->id);

        $this->assertSame(0, $DB->count_records('elby_rise_reviews', ['reviewedby' => $reviewer->id]));
        $this->assertSame(0, $DB->count_records('elby_rise_corrections', ['reviewedby' => $reviewer->id]));
        // The records themselves are retained, only the identity is detached.
        $this->assertSame(1, $DB->count_records('elby_rise_reviews', ['applicantid' => 'app1']));
        $this->assertSame(1, $DB->count_records('elby_rise_corrections', ['applicantid' => 'app1']));
    }

    public function test_provision_revokes_correction_tokens_once_identity_clean(): void {
        $this->resetAfterTest();

        // A live correction link exists from an earlier action-requested round.
        rise_token::mint(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1');
        $this->assertTrue(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));

        // Clean identity provisions with action 'ok' -> the stale link is revoked.
        $this->approve();
        [$service] = $this->make_service($this->applicant());
        $result = $service->provision('camp1', 'app1');
        $this->assertSame('ok', $result['action']);
        $this->assertFalse(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));
    }

    public function test_revoke_correction_tokens_on_every_resolution_path(): void {
        global $DB;
        $this->resetAfterTest();

        $service = new rise_user_service();
        $reviewid = $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'action_requested',
            'nidstatus' => 'pending', 'nidverified' => 0, 'comment' => 'Fix it',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        rise_token::mint(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1');

        // Action still outstanding: the link survives.
        $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], '*', MUST_EXIST);
        $service->revoke_correction_tokens_if_resolved($review);
        $this->assertTrue(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));

        // Resolved without provisioning (e.g. approved by a reviewer whose save
        // deferred provisioning): the link is revoked.
        $DB->set_field('elby_rise_reviews', 'nesastatus', 'approved', ['id' => $reviewid]);
        $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], '*', MUST_EXIST);
        $service->revoke_correction_tokens_if_resolved($review);
        $this->assertFalse(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));
    }

    public function test_tokens_are_hashed_single_use_and_expiring(): void {
        global $DB;
        $this->resetAfterTest();

        $raw = rise_token::mint(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $raw);

        // Only the hash is stored.
        $record = $DB->get_record('elby_rise_tokens', ['applicantid' => 'app1'], '*', MUST_EXIST);
        $this->assertNotEquals($raw, $record->tokenhash);
        $this->assertSame(hash('sha256', $raw), $record->tokenhash);

        $this->assertTrue(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));
        $valid = rise_token::validate($raw, rise_token::PURPOSE_CORRECTION);
        $this->assertNotNull($valid);

        // Wrong purpose is rejected.
        $this->assertNull(rise_token::validate($raw, rise_token::PURPOSE_SETPASSWORD));

        // Single use, atomically: exactly one consumer wins.
        $this->assertTrue(rise_token::try_consume($valid->id));
        $this->assertFalse(rise_token::try_consume($valid->id));
        [$status] = rise_token::check($raw, rise_token::PURPOSE_CORRECTION);
        $this->assertSame('used', $status);
        $this->assertFalse(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));

        // Expired tokens are rejected.
        $raw2 = rise_token::mint(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1');
        $DB->set_field('elby_rise_tokens', 'expires', time() - 10,
            ['tokenhash' => hash('sha256', $raw2)]);
        [$status2] = rise_token::check($raw2, rise_token::PURPOSE_CORRECTION);
        $this->assertSame('expired', $status2);

        // Minting revokes the previous unused token for the same purpose+applicant.
        $raw3 = rise_token::mint(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1');
        $this->assertNull(rise_token::validate($raw2, rise_token::PURPOSE_CORRECTION));
        $this->assertNotNull(rise_token::validate($raw3, rise_token::PURPOSE_CORRECTION));
    }

    public function test_notify_resends_once_phone_becomes_valid(): void {
        global $DB;
        $this->resetAfterTest();

        [$service] = $this->make_service($this->applicant());
        $reviewid = $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'action_requested',
            'nidstatus' => 'pending', 'nidverified' => 0, 'comment' => 'Fix your NID',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], '*', MUST_EXIST);

        // Invalid phone: skipped + flagged, hash recorded so it doesn't spin.
        $service->notify_learner($review, ['_id' => 'app1', 'phone' => '12345']);
        $this->assertSame(1, $DB->count_records('elby_rise_sms_log', ['applicantid' => 'app1', 'purpose' => 'action']));

        // Same bad phone, unchanged payload: deduped.
        $service->notify_learner($review, ['_id' => 'app1', 'phone' => '12345']);
        $this->assertSame(1, $DB->count_records('elby_rise_sms_log', ['applicantid' => 'app1', 'purpose' => 'action']));

        // Phone corrected upstream: the dedupe key changes and the learner is re-notified.
        $service->notify_learner($review, ['_id' => 'app1', 'phone' => '0788123456']);
        $this->assertSame(2, $DB->count_records('elby_rise_sms_log', ['applicantid' => 'app1', 'purpose' => 'action']));
    }

    public function test_notify_learner_dedupes_and_resends_when_token_dead(): void {
        global $DB;
        $this->resetAfterTest();

        [$service] = $this->make_service($this->applicant());
        $reviewid = $DB->insert_record('elby_rise_reviews', (object) [
            'campaignid' => 'camp1', 'applicantid' => 'app1', 'nesastatus' => 'action_requested',
            'nidstatus' => 'pending', 'nidverified' => 0, 'comment' => 'Fix your NID',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], '*', MUST_EXIST);
        $service->notify_learner($review);
        $this->assertSame(1, $DB->count_records('elby_rise_sms_log', ['applicantid' => 'app1', 'purpose' => 'action']));
        $this->assertNotEmpty($review->lastnotifiedhash);

        // Unchanged payload + live token: deduped.
        $service->notify_learner($review);
        $this->assertSame(1, $DB->count_records('elby_rise_sms_log', ['applicantid' => 'app1', 'purpose' => 'action']));

        // Same payload but the correction token expired: must resend with a fresh link.
        $DB->set_field('elby_rise_tokens', 'expires', time() - 10, ['applicantid' => 'app1']);
        $service->notify_learner($review);
        $this->assertSame(2, $DB->count_records('elby_rise_sms_log', ['applicantid' => 'app1', 'purpose' => 'action']));
        $this->assertTrue(rise_token::has_active(rise_token::PURPOSE_CORRECTION, 'camp1', 'app1'));
    }
}
