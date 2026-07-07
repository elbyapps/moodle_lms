# RISE User Provisioning Implementation — Full Source Review Report

Reviewed: current uncommitted implementation of `vendor/local_elby_dashboard/docs/rise-user-provisioning-plan.html`
Date: 2026-07-07
Scope: full source review of the RISE user-provisioning implementation across PHP services/classes/pages, privacy provider, DB schema/upgrade/tasks/messages, and AMD/React UI.

## Check run summary

Commands run:

- `git status --short && git diff --stat -- vendor/local_elby_dashboard` — inspected changed/untracked scope.
- `find vendor/local_elby_dashboard -maxdepth 3 -type f` — enumerated plugin source files.
- `./node_modules/.bin/vite build` from `vendor/local_elby_dashboard` — **passed**.
- `./node_modules/.bin/tsc --noEmit` from `vendor/local_elby_dashboard` — **failed** on existing TypeScript issues outside the new RISE component path, e.g. `AdminPanel.tsx`, `CoursesReport.tsx`, `Dashboard.tsx`, `SchoolDetail.tsx`.
- `php -v` — **failed** because PHP is unavailable in this environment (`php: command not found`). PHP lint/PHPUnit were not run.

## Overall verdict

Many of the earlier high-risk RISE findings are fixed, including server-side identity validation, token write-path guarding, privacy erasure of reviewer/RISE identifiers, correction-file token authorization, and provisioning/review-save serialization.

However, the full source pass found one upgrade blocker, one high-risk authorization issue, and several medium security/privacy/data-integrity issues that should be addressed before shipping.

## Previously reviewed fixes confirmed

- `validate_nid()` re-fetches RISE identity server-side before comparing NIDA/TMIS data: `classes/external/rise.php:559-562`.
- `save_review()` ignores browser-supplied `nidverified` and preserves server-owned NIDA state: `classes/external/rise.php:431-436`.
- `save_review()` shares the per-applicant provisioning lock: `classes/external/rise.php:393-398`.
- `provision()` uses the shared applicant/NID locks and re-checks approval under lock before create/link: `classes/rise_user_service.php:367-384`, `428-437`.
- Correction-token write and file-access paths are guarded by current outstanding action state.
- Privacy deletion/anonymization now covers learner RISE sync state, reviewer identifiers, and retained external applicant IDs.

## Findings

### Blocker — plugin version is ahead of the final upgrade savepoint

**Files:**
- `version.php:28`
- `db/upgrade.php:236-248`

`version.php` declares plugin version `2026070702`, but `db/upgrade.php` only advances the upgrade savepoint to `2026070701` before returning.

Sites upgrading to `2026070702` can be left with a stale plugin version/savepoint state and may repeatedly attempt the upgrade or fail Moodle's upgrade consistency checks.

**Recommendation:** add an upgrade block/savepoint for `2026070702`, or align `$plugin->version` with the final upgrade savepoint.

### High — RISE approval/write decisions require only `viewreports`

**Files:**
- `classes/external/rise.php:361-364`
- `db/services.php:268-275`
- `db/access.php:41-49`
- `classes/task/ensure_rise_users.php:63-74`

`save_review()` permits review decisions, including `approved`, with only `local/elby_dashboard:viewreports`. That capability is granted to teacher and editingteacher archetypes. Even if such a user lacks `manageriseusers`, cron later provisions all approved reviews.

This makes broad report access sufficient to trigger account creation/linking unless every `viewreports` holder is intentionally a RISE/NESA approver.

**Recommendation:** introduce/use a dedicated write capability for RISE review decisions, e.g. `local/elby_dashboard:reviewriseusers`, and reserve approval decisions for that capability or `manageriseusers`.

### Medium — `validate_nid()` can race provisioning state

**Files:**
- `classes/external/rise.php:603-604`
- `classes/external/rise.php:668-680`
- `classes/rise_user_service.php:282`
- `classes/rise_user_service.php:432`, `448-455`

`validate_nid()` writes `nidstatus` / `nidverified` through `set_nid_status()` without taking the shared per-applicant provisioning lock. That state feeds `evaluate_action()` and provisioning persists `provisioningaction` from the review state it read under lock.

A concurrent NID validation can therefore interleave with provisioning and leave stale `provisioningaction` relative to the final NIDA status, for example `provisioningaction = ok` while `nidstatus = mismatch`.

**Recommendation:** wrap `set_nid_status()` review writes in `rise_user_service::with_applicant_lock()`, or re-read/reconcile NIDA state immediately before saving provisioning action.

### Medium — province filter is ignored on DB-backed applicant list path

**Files:**
- `classes/external/rise.php:87`
- `classes/external/rise.php:135-140`
- `classes/external/rise.php:157-182`

`provincecode` is forwarded on the remote RISE API path, but when NESA/NIDA filters switch `get_applicants()` to the local DB snapshot path, the province filter is not applied. Filtered lists can include learners from other provinces.

**Recommendation:** persist province code/name in the review snapshot columns or parse/filter it from `applicantdata` on the DB path.

### Medium — `get_user_status()` trusts browser-supplied NIDs for account lookup

**Files:**
- `classes/external/rise.php:755-789`
- `classes/rise_user_service.php:1195-1209`
- `classes/rise_user_service.php:1258-1270`

`get_user_status()` accepts applicant/NID pairs from the browser and queries Moodle users by `idnumber` without validating that the NID belongs to that RISE applicant/campaign. A `viewreports` user can submit arbitrary NIDs and enumerate matching RISE-shaped Moodle accounts, including user IDs, usernames, suspension state, and profile URLs.

**Recommendation:** resolve status only from server-fetched RISE applicants or stored review snapshots, or validate each supplied pair against RISE before querying Moodle users by NID.

### Medium — concurrent token minting can leave multiple active links

**Files:**
- `classes/rise_token.php:70-76`
- `classes/rise_token.php:147-158`

`rise_token::mint()` intends to keep at most one live token per purpose/applicant, but it performs delete-then-insert without a lock or DB uniqueness constraint. Concurrent sends can leave multiple active password/correction links. `try_consume()` serializes consumption of a single token ID, not minting per applicant/purpose.

**Recommendation:** add a lock around minting keyed by purpose/campaign/applicant, or enforce a DB-level uniqueness strategy for active tokens.

### Medium — TMIS/NIDA 404 becomes hard AJAX failure instead of `found:false`

**Files:**
- `classes/external/rise.php:580-581`
- `classes/tmis_client.php:152-153`
- `amd/src/types.ts:230-235`

`tmis_client::get_citizen()` throws `tmisnotfound` on 404, and `validate_nid()` does not catch it. The frontend/API model supports a normal `found: false` result, but a valid “NID not found” outcome becomes an AJAX error and does not persist a mismatch/not-found review state.

**Recommendation:** catch `tmisnotfound` in `validate_nid()`, persist an appropriate non-verified/mismatch state, and return `found:false`.

### Medium — NID verification checkbox is mutable but ignored by backend

**Files:**
- `amd/src/components/Rise.tsx:804-810`
- `amd/src/components/Rise.tsx:829-831`
- `classes/external/rise.php:431-436`

The review UI exposes a mutable “National ID verified” control and sends `nidverified`, but the backend intentionally ignores browser-supplied verification and preserves the server-owned DB value. This can mislead reviewers into believing they changed verification state.

**Recommendation:** remove the checkbox, make it read-only/server-derived, or route verification through the explicit server-side `validate_nid()` flow only.

### Medium — privacy export omits declared/stored personal data

**Files:**
- `classes/privacy/provider.php:70-75`
- `classes/privacy/provider.php:156-260`
- `classes/rise_user_service.php:1100-1103`
- `classes/rise_user_service.php:1134-1142`

The privacy provider declares `elby_rise_tokens` metadata and includes token rows in discovery, but `export_user_data()` does not export token rows. SMS `error` may contain raw phone data, is stored in `elby_rise_sms_log.error`, and is not declared/exported.

**Recommendation:** export token metadata rows for the user, and declare/export or sanitize the SMS `error` field so no raw personal data is omitted from privacy export.

### Medium — unused welcome token remains a password-reset bearer token for 24h

**Files:**
- `rise_setpassword.php:91-130`
- `classes/rise_token.php:45-47`
- `classes/rise_user_service.php:654-655`

`rise_setpassword.php` accepts any valid unused set-password token and active user. It does not require the account to still be in its initial force-change/first-access state before changing the password. If the learner's account state changes through another path while the welcome token remains unused, that token can still reset the password until expiry.

**Recommendation:** before accepting a set-password token, require the user still has `auth_forcepasswordchange` set and/or `firstaccess = 0`, or revoke welcome tokens once the account first authenticates/changes password.

## Validation gaps

- PHP syntax lint and PHPUnit could not be run because PHP is unavailable.
- No deterministic concurrent contention tests were found for lock behavior.
- TypeScript `tsc --noEmit` currently fails on pre-existing/non-RISE components, even though Vite production build passes.

## Recommended merge decision

Do not ship until the upgrade-version blocker and high-risk review-approval capability issue are fixed. The medium findings should be triaged before production rollout, especially the NID/provisioning race, NID enumeration, token mint concurrency, and set-password token semantics.
