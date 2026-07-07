# RISE User Provisioning Plan — Final Validation

Validated: `vendor/local_elby_dashboard/docs/rise-user-provisioning-plan.html`
Basis: current source under `vendor/local_elby_dashboard/`

## Verdict

The cleanup is done. The plan now validates against the current source and is ready to hand to an implementation worker.

The previous remaining issues have been addressed:

- RISE sync retry/error fields were added: `risesyncstatus`, `risesyncerror`, `risesyncedat`.
- Stale `snapshot-authoritative` delivery wording was replaced with server-authoritative `get_applicant()` provisioning.
- Frontend status wording now uses visible `{applicantid, nid}` pairs.
- `lastnotifiedhash` now explicitly works with token-aware resend checks.
- RISE PATCH whitelist/NID writability is now a blocking pre-implementation check with graceful fallback.

## Source-backed validation notes

### Server-side identity authority

The plan correctly requires provisioning identity to come from `rise_client::get_applicant($applicantid)`, not the browser-supplied review snapshot.

This is important because current source still sends and stores browser snapshot data:

- `Rise.tsx` sends `JSON.stringify(applicant)` from the browser: `amd/src/components/Rise.tsx:791-799`.
- `save_review()` accepts `applicantdata` as `PARAM_RAW`: `classes/external/rise.php:330-339`.
- `save_review()` stores snapshot fields directly: `classes/external/rise.php:384-416`.

The plan now explicitly says that snapshot is display/cache fallback only and must not feed Moodle identity, SMS phone, or RISE `linkedUserId` unless refreshed server-side.

### RISE linkedUserId back-write

The plan now includes distributed consistency handling:

- Moodle remains source of truth after user creation/linking.
- RISE PATCH failure does not roll back Moodle provisioning.
- Failed sync is recorded in `risesyncstatus` / `risesyncerror` and retried by backfill.
- Existing different `linkedUserId` is treated as conflict.

This is sufficient for implementation.

### Capability and frontend gating

The plan now includes all required plumbing:

- `manageriseusers` capability in `db/access.php`,
- lang string,
- endpoint service registration,
- PHP-side `require_capability()` checks,
- `canManageRiseUsers` passed from `rise.php`,
- frontend hide/disable logic.

This matches the current source gap where only `view`, `viewreports`, and `manage` exist today, and `rise.php` currently passes `isAdmin` only.

### File handling

The plan now explicitly requires extending `local_elby_dashboard_pluginfile()` for `rise_idcard` / `rise_nesaresult` with strict reviewer/linked-learner/token access checks.

This addresses the current source limitation where `pluginfile()` only serves `logo`.

### Notifications and tokens

The plan now avoids the expired-token dedupe bug by requiring a resend whenever no active unexpired unused correction token exists, even if `lastnotifiedhash` is unchanged.

### Frontend/export details

The plan now covers:

- `get_user_status` using `{applicantid, nid}` pairs,
- `stopPropagation()` for row-level create buttons,
- export-wide status fetching for all exported rows.

## Minor implementation nits, not blockers

- The delivery phase summary for schema mentions `lastnotifiedhash/at` and `userid` but not the new `risesync*` fields. The data model itself includes them, so this is only a wording nit.
- One duplicate-NID rule still says “If their name disagrees with the snapshot”; during implementation this should mean disagreement with the server-fetched RISE applicant, not the browser snapshot.

## Final recommendation

Proceed to implementation. Use the plan as the worker handoff, with the two minor wording nits above kept in mind during coding.
