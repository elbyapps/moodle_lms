# 13. Appendix

## 13.1 Configuration Options

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| sdms_api_url | URL | - | SDMS API base URL (IP whitelist auth, no key needed) |
| sdms_timeout | Integer | 30 | HTTP request timeout in seconds |
| sdms_cache_ttl | Integer | 604800 | Cache TTL in seconds (7 days) |
| metrics_retention_days | Integer | 365 | How long to keep metrics |
| sync_on_login | Boolean | true | Sync user on login |
| sync_on_enroll | Boolean | true | Sync user on enrollment |
| batch_size | Integer | 100 | Records per batch in scheduled tasks |

## 13.2 Error Codes

| Code | Message | Resolution |
|------|---------|------------|
| SDMS001 | API connection failed | Check network, verify URL |
| SDMS002 | Authentication failed | Verify API key |
| SDMS003 | Record not found | SDMS ID doesn't exist |
| SDMS004 | Rate limit exceeded | Wait and retry |
| SDMS005 | Invalid response format | Contact SDMS team |
| SYNC001 | User has no SDMS ID | Configure idnumber field |
| SYNC002 | School sync failed | Check school code |
| METR001 | No activity data | User has no logs for period |

## 13.3 Monitoring Queries

```sql
-- Check sync status
SELECT
    sync_type,
    operation,
    COUNT(*) as count,
    AVG(response_time_ms) as avg_response_ms
FROM {elby_sync_log}
WHERE timecreated > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR))
GROUP BY sync_type, operation;

-- Find stale caches
SELECT COUNT(*) as stale_users
FROM {elby_sdms_users}
WHERE last_synced < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY));

-- Metrics computation lag
SELECT
    MAX(FROM_UNIXTIME(period_end)) as latest_metrics,
    TIMESTAMPDIFF(HOUR, MAX(FROM_UNIXTIME(period_end)), NOW()) as lag_hours
FROM {elby_user_metrics}
WHERE period_type = 'hourly';
```

## 13.4 Glossary

| Term | Definition |
|------|------------|
| SDMS | Student Data Management System - authoritative source for student/school records |
| RTB | Rwanda TVET Board |
| TVET | Technical and Vocational Education and Training |
| Opportunistic Sync | Data synchronization triggered by user actions rather than scheduled bulk operations |
| At-Risk | Student classification for those with no LMS activity in >7 days |
| Engagement Tier | Classification of students by activity level (High/Medium/Low) |
| Write Amplification | Database performance issue caused by too many concurrent write operations |
| Hybrid Metrics | Strategy combining scheduled tasks (high-volume) with event observers (low-volume) |

## 13.5 Quick Reference: Hybrid Metrics Strategy

This is a summary of how metrics are captured in the system:

### Real-Time (Event Observers) — Low Volume, High Value

| Event | Observer | Updates |
|-------|----------|---------|
| Quiz submitted | `\mod_quiz\event\attempt_submitted` | quizzes_attempted, quizzes_avg_score |
| Assignment submitted | `\mod_assign\event\assessable_submitted` | assignments_submitted |
| Course completed | `\core\event\course_completed` | course_progress = 100 |
| Activity completed | `\core\event\course_module_completion_updated` | activities_completed, course_progress |
| User login | `\core\event\user_loggedin` | SDMS sync (async) |
| User enrolled | `\core\event\user_enrolment_created` | SDMS sync (async) |

### Scheduled (Hourly Task) — High Volume

| Metric | Source Table | Frequency |
|--------|--------------|-----------|
| total_actions | mdl_logstore_standard_log | Hourly |
| active_days | mdl_logstore_standard_log | Hourly |
| time_spent_seconds | mdl_logstore_standard_log | Hourly |
| resources_viewed | mdl_logstore_standard_log | Hourly |
| pages_viewed | mdl_logstore_standard_log | Hourly |
| forum_views | mdl_logstore_standard_log | Hourly |
| forum_posts | mdl_logstore_standard_log | Hourly |

### NOT Observed (Would Cause Write Amplification)

These events are **intentionally not observed** due to high volume:

- `\core\event\course_viewed` — User enters course
- `\core\event\course_module_viewed` — User views any activity
- `\mod_resource\event\course_module_viewed` — User views resource
- `\mod_page\event\course_module_viewed` — User views page
- `\mod_forum\event\discussion_viewed` — User views forum thread

---

*Document End*
