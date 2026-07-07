# RISE Recent Changes Review

Reviewed: current uncommitted/recent changes in `vendor/local_elby_dashboard`
Date: 2026-07-07
Scope: follow-up review of RISE SMS notification report/backlog changes, including prior findings around SMS-log authorization and backlog materialization.

## Check run summary

Commands run:

- `git diff --check -- vendor/local_elby_dashboard` — **passed**.
- `./node_modules/.bin/vite build` from `vendor/local_elby_dashboard` — **passed**.
- `./node_modules/.bin/tsc --noEmit` from `vendor/local_elby_dashboard` — **failed** on existing/non-RISE TypeScript issues in `AdminPanel.tsx`, `CoursesReport.tsx`, `Dashboard.tsx`, and `SchoolDetail.tsx`.
- `php -v` — **failed** because PHP is unavailable in this environment (`php: command not found`). PHP lint/PHPUnit were not run.

## Verdict

The prior high-severity SMS-log exposure finding is fixed, and the prior backlog materialization issue is addressed server-side. No blocker/high findings remain in this pass. One medium UX/operational accuracy issue remains: the admin-panel confirmation implies that one click queues the full backlog even though the server intentionally queues only one 500-row batch.

## Confirmed fixed

- SMS log access is now gated by `local/elby_dashboard:manageriseusers` instead of broad `viewreports`: `classes/external/rise.php:975-980`.
- Web service registration matches the tighter SMS-log capability: `db/services.php:300-306`.
- The frontend SMS notifications entry/report is gated on `user.canManageRiseUsers`: `amd/src/components/Rise.tsx:479-483`, `2209-2210`, `2227`.
- SMS log API still avoids returning stored SMS message bodies/tokens; it returns metadata/status/error fields only: `classes/external/rise.php:1054-1064`. Stored messages are token-redacted at insert: `classes/rise_user_service.php:1237-1244`.
- Backlog preview now uses a count-only query instead of materializing every row: `classes/rise_user_service.php:361-364`, `classes/external/rise.php:1129-1135`.
- Web backlog execution is capped to one batch of 500 rows: `classes/external/rise.php:241-242`, `1138-1149`.
- Service/version upgrade alignment is present for the new/tightened services: `db/services.php:300-314`, `472-476`; `db/upgrade.php:276-287`; `version.php:28`.

## Remaining finding

### Medium — backlog UI overstates what one click will queue for large backlogs

**Files:**
- `amd/src/components/AdminPanel.tsx:677-688`
- `classes/external/rise.php:241-242`
- `classes/external/rise.php:1138-1149`

The admin panel displays the full backlog count and the button/confirmation says it will queue/send SMS for that full count. The API intentionally queues only up to `BACKLOG_BATCH = 500` per web request and returns `remaining` for larger backlogs.

For counts greater than 500, the UI is misleading: one click queues only a batch, not every displayed learner.

**Recommendation:** update the UI copy and result handling to disclose the batch size, e.g. “Queue up to 500 of N notifications”, and show the returned `remaining` count after execution.

## Recommended merge decision

No blocker/high issues were found. Fix the medium backlog-copy/result mismatch before production rollout, or explicitly accept it as an admin UX limitation.
