# RISE User Provisioning Plan — Review Report

Reviewed: `vendor/local_elby_dashboard/docs/rise-user-provisioning-plan.html`
Context: current `local_elby_dashboard` plugin implementation

## Summary

The plan is directionally strong. The major architectural choices are sound:

- centralizing provisioning logic in a reusable `rise_user_service`,
- using `user.idnumber = nid` as the primary link key,
- making provisioning idempotent across approval, manual action, and backfill,
- adding dashboard account visibility,
- separating account creation behind a stronger capability,
- deferring course enrolment.

However, several parts should be corrected before implementation. The biggest risks are around endpoint contracts, trusting browser-supplied applicant data, public token security, password-reset delivery, file uploads, and overloaded state fields.

## Blockers / Must Fix Before Implementation

### 1. `rise_get_user_status` cannot resolve unreviewed learners as specified

The plan defines:

```text
campaignid, applicantids[]
```

For learners without an `elby_rise_reviews` row, the backend has no NID available to match against `user.idnumber`. Therefore the endpoint cannot surface existing accounts for unreviewed learners from applicant IDs alone.

**Recommendation:** pass either:

- `{applicantid, nid}` pairs,
- applicant snapshots,
- or have the backend fetch applicant details from RISE server-side.

### 2. Manual create must not trust browser-supplied `applicantdata`

The proposed `rise_create_user(campaignid, applicantid, applicantdata)` endpoint allows privileged frontend code to submit altered names, NIDs, phone numbers, or emails.

**Recommendation:** the backend should use an existing review snapshot or re-fetch applicant data from the RISE API. Browser-supplied snapshot data should be treated as a cache hint only, not authoritative identity data.

### 3. SMS password reset / set-password flow is underspecified

The plan says to reuse Moodle forgot-password or mint a reset token. Core forgot-password is email-oriented and will not help learners with synthetic emails such as `username@learner.rise.reb.rw`.

**Recommendation:** define a custom one-time set-password flow with:

- expiry,
- single-use token,
- secure random token value,
- hashed token storage,
- audit logging,
- rate limiting,
- clear invalid/expired UX.

### 4. Correction-form token is not actually time-limited

The plan says the token is time-limited, but the example is:

```text
hmac = sha256(applicantid.campaignid.secret)
```

That token never expires and cannot be revoked.

**Recommendation:** use either:

- a signed payload containing `applicantid`, `campaignid`, `exp`, and a nonce, or
- a stored DB token with expiry, single-use state, and revocation support.

### 5. Public file upload through an external AJAX method is risky

The plan proposes a public `rise_submit_correction` external method with uploads. Moodle external AJAX methods are not the simplest fit for multipart, no-login file uploads.

**Recommendation:** implement `rise_action.php` as a normal form POST handler or Moodle form endpoint. Store files through the File API after strict validation.

Minimum controls:

- file size limit,
- MIME/type validation,
- image/PDF allowlist,
- rate limit,
- token validation before accepting files,
- malware scanning if available.

### 6. `useraction` is overloaded

The plan defines `useraction` for learner-facing provisioning actions:

- `ok`,
- `nid_missing`,
- `nid_invalid`,
- `details_mismatch`,
- `duplicate_nid`.

Later it also uses `resubmitted`, which is reviewer-facing correction state.

**Recommendation:** split state into separate fields, for example:

- `provisioningaction`,
- `correctionstatus`,
- `lastnotifiedhash`,
- `lastnotifiedat`,
- optionally `smsstatus` or a separate SMS log table.

### 7. Username generation is MySQL-specific and concurrency-fragile

The proposed SQL uses:

```sql
CAST(username AS UNSIGNED)
```

This is not portable Moodle SQL and the `MAX() + 1` approach can collide under concurrent approvals.

**Recommendation:** use Moodle's Lock API around username generation, or create a small sequence table keyed by `{type, year}`.

## High-Priority Issues

### Capability model needs tightening

Current `save_review()` only requires:

```php
local/elby_dashboard:viewreports
```

If approval now creates Moodle user accounts, this action should require a stronger capability.

**Recommendation:** introduce or reuse a write capability for NESA review/account provisioning, such as:

```text
local/elby_dashboard:manageriseusers
```

At minimum, account creation should not be possible with read-only report access.

### `parse_sdms_names()` is not directly reusable

The plan says to reuse `parse_sdms_names()`, but it is currently a private method in `classes/external/signup.php`.

**Recommendation:** move name parsing to a shared helper/service, or duplicate it intentionally in `rise_user_service` with tests.

### Duplicate-NID handling needs more precise rules

The plan should distinguish:

1. one active Moodle user has the NID → link,
2. multiple active users have the NID → block and escalate,
3. user is already linked to another RISE review → define whether this is valid or a conflict,
4. matching user is deleted/suspended → define expected behaviour.

### Email uniqueness needs explicit handling

If applicant email is used when present, it may already belong to another Moodle user.

**Recommendation:** define fallback logic for duplicate/invalid emails. Synthetic email generation should also be unique and deterministic.

### Notification dedupe needs storage

The plan says to dedupe unchanged `(nesastatus, comment)` notifications, but no schema supports that.

**Recommendation:** store notification hash and timestamp, or add an SMS/notification log table.

### Test credentials should not be documented

The plan includes the test Basic auth value `reb:reb`. Even test credentials should not be normalized in committed implementation docs.

**Recommendation:** remove credential examples and only document the configuration keys.

## Medium-Priority Issues

### Backend section says two methods but lists three

The text says “Two thin methods” while the table lists:

- `rise_get_user_status`,
- `rise_create_user`,
- `rise_submit_correction`.

Update wording to avoid confusion.

### TypeScript action enum does not match plan states

The frontend type lists:

```ts
'ok'|'nid_missing'|'nid_invalid'|'details_mismatch'|'duplicate_nid'|'none'
```

but the form workflow also introduces `resubmitted`.

If state is split as recommended, frontend types should reflect the separate state fields.

### “Fails checksum” needs confirmation

The plan says invalid NID means “not 16 digits / fails checksum.” Confirm whether Rwanda NID checksum validation is available and intended. If not, document only 16-digit format validation.

### Testing checklist has a notification conflict

The checklist says valid approval creates a user with “no notification,” but account creation is also expected to send a welcome SMS.

**Recommendation:** change this to “no action-needed notification.”

### Backfill criteria should be explicit

The plan mentions approved/enrolled learners. Clarify whether backfill processes:

- `nesastatus = approved`,
- remote applicant `status = ENROLLED`,
- either condition,
- or both conditions.

## Suggested Plan Amendments

Before coding, revise the HTML plan to include:

1. final endpoint contracts,
2. authoritative source rules for applicant data,
3. secure token design for correction links,
4. secure token design for set-password links,
5. split provisioning/correction/notification state fields,
6. Moodle-portable username sequencing,
7. stricter capability model,
8. notification dedupe schema,
9. file upload implementation details,
10. explicit duplicate-NID conflict rules.

## Recommended Implementation Order

1. Schema/state redesign.
2. `rise_user_service` with link-first provisioning and tests.
3. Secure username generation.
4. Backend endpoints with hardened capability checks.
5. Approval hook, manual create, and backfill using the same service.
6. SMS client and notification log/dedupe.
7. Password set-token flow.
8. Correction form and upload handling.
9. Frontend account column/drawer updates.
10. Profile badge.

## Final Recommendation

Do not implement the plan exactly as written yet. Update the design for the blockers above first. Once those are addressed, the proposed architecture should be a solid basis for implementation.
