# RISE User Provisioning Implementation — Review Report

Reviewed: current uncommitted implementation of `vendor/local_elby_dashboard/docs/rise-user-provisioning-plan.html`
Date: 2026-07-07
Scope: post-feedback full-source follow-up review, focused on previously reported blocker/high/medium findings plus security/privacy/token regression checks.

## Check run summary

Commands run:

- `./node_modules/.bin/vite build` from `vendor/local_elby_dashboard` — **passed**.
- `./node_modules/.bin/tsc --noEmit` from `vendor/local_elby_dashboard` — **failed** on existing/non-RISE TypeScript issues in `AdminPanel.tsx`, `CoursesReport.tsx`, `Dashboard.tsx`, and `SchoolDetail.tsx`.
- `php -v` — **failed** because PHP is unavailable in this environment (`php: command not found`). PHP lint/PHPUnit were not run.

## Overall verdict

The previous upgrade blocker and several high/medium security and UX findings were fixed. Remaining issues are concentrated around provisioning’s treatment of TMIS/NIDA 404s, `get_user_status()` trusting caller-supplied NIDs, privacy coverage for newly stored fields, and a correction-submit race with reviewer resolution.

## Confirmed fixed in this pass

- Upgrade version/savepoint now align at `2026070703`: `version.php:28`, `db/upgrade.php:248-273`.
- Approval now requires `local/elby_dashboard:manageriseusers` server-side: `classes/external/rise.php:382-390`.
- The UI disables approval for users without `canManageRiseUsers`: `amd/src/components/Rise.tsx:856-863`.
- Province filtering is now persisted/backfilled and applied on the DB-backed applicant path: `classes/external/rise.php:175-177`, `classes/external/rise.php:440`, `classes/external/rise.php:749`, `db/upgrade.php:248-273`, `db/install.xml:361`.
- Token minting now serializes revoke/insert per purpose/applicant: `classes/rise_token.php:70-97`.
- Token consumption remains lock-protected: `classes/rise_token.php:156-174`.
- `validate_nid()` now treats TMIS/NIDA 404 as a normal `found:false` result and persists mismatch: `classes/external/rise.php:599-624`.
- NID verification UI is now read-only/server-derived: `amd/src/components/Rise.tsx:831-840`; backend still preserves server-owned NIDA state: `classes/external/rise.php:445-450`.
- Set-password tokens are now first-password only and are consumed if the account is already established: `rise_setpassword.php:108-116`; password change still consumes before mutation: `rise_setpassword.php:132-142`.
- Correction-file access remains gated by reviewer/linked learner/valid token and outstanding action state: `lib.php:151-202`.
- Privacy export now includes non-secret token metadata: `classes/privacy/provider.php:265-288`.

## Remaining findings

### High — provisioning still treats TMIS/NIDA 404 as best-effort and may create accounts

**Files:**
- `classes/rise_user_service.php:597-603`
- `classes/rise_user_service.php:482`
- `classes/rise_user_service.php:527-532`
- `classes/tmis_client.php:152-153`

The interactive `validate_nid()` path now handles TMIS/NIDA 404 correctly, but provisioning’s server-side NIDA re-check still catches all exceptions and returns without changing `nidstatus`.

`tmis_client` uses `tmisnotfound` for a real 404. During manual/backfill provisioning, that explicit “NID does not exist” result is treated the same as a transient gateway failure. If the review’s stored `nidstatus` is still `pending`, `evaluate_action()` can return `ok`, and provisioning can create/link the Moodle account.

**Recommendation:** in `rise_user_service::verify_nida()`, handle `tmisnotfound` separately by setting `nidstatus = mismatch` / `nidverified = 0`, then ensure provisioning returns a blocked/details-mismatch action instead of creating/linking as OK.

### High — `get_user_status()` still trusts caller-supplied NIDs

**Files:**
- `classes/external/rise.php:810-845`
- `classes/rise_user_service.php:1211-1224`
- `classes/rise_user_service.php:1273-1285`
- `db/services.php:284-290`
- `db/access.php:41-48`

`get_user_status()` accepts browser-supplied `pairs[].nid`, queries Moodle users by those NIDs, and returns account identifiers/profile URLs when a matching RISE-shaped account is found.

Format checks and `is_linkable()` reduce accidental leakage, but they do not prove that the supplied NID belongs to the requested RISE applicant/campaign. For users with `viewreports`, this remains a targeted account-existence oracle for arbitrary valid-format NIDs.

**Recommendation:** resolve account status from server-fetched RISE applicants or stored review snapshots only, or validate each supplied applicant/NID pair against RISE server-side before querying `user.idnumber`.

### Medium — privacy export/erasure incomplete for new/stored review fields

**Files:**
- `classes/privacy/provider.php:57-68`
- `classes/privacy/provider.php:171-183`
- `classes/privacy/provider.php:381-405`
- `db/install.xml:361`
- `db/install.xml:363`
- `db/install.xml:365`

Review rows store personal/location fields including `provincecode`, `applicantdata`, and `nesaindexnumber`. `applicantdata` is declared in metadata, but review export only emits summary fields and omits `applicantdata` and `nesaindexnumber`. The new `provincecode` field is stored and used for filtering, but it is not declared/exported and is not cleared during learner deletion.

**Recommendation:** declare/export all retained personal review fields consistently, and clear/anonymize `provincecode` during learner deletion if it is not intentionally retained under a documented basis.

### Medium — correction submit can race reviewer resolution

**Files:**
- `rise_action.php:119-129`
- `rise_action.php:208-274`

The correction form checks `action_outstanding()` once before submission. Later, the POST path consumes the token, inserts a correction, uploads files, marks `correctionstatus = resubmitted`, and PATCHes RISE without taking the per-applicant provisioning/review lock or performing a fresh locked outstanding-action check.

A learner POST racing a reviewer resolution can still submit a correction after the action has been resolved. This is especially relevant for session-based access, where no token consumption narrows the race.

**Recommendation:** wrap correction submit in the same per-applicant lock used by review/provisioning decisions and re-check `action_outstanding()` immediately before insert/upload/PATCH side effects.

## Validation gaps

- PHP syntax lint and PHPUnit could not be run because PHP is unavailable.
- No deterministic concurrent contention tests were observed for correction-submit vs reviewer-resolution races.
- TypeScript `tsc --noEmit` still fails on existing/non-RISE components, although Vite production build passes.

## Recommended merge decision

Do not ship until the two high-severity issues are fixed or explicitly accepted with documented risk. The medium privacy and correction-race findings should also be resolved before production rollout.
