# 10. Scheduled Tasks

## 10.1 Task Overview

The scheduled tasks handle **high-volume metrics** that would cause write amplification if processed via event observers. Low-volume events (submissions, completions) are handled in real-time by observers.

| Task | Frequency | Purpose | Why Scheduled (not Event) |
|------|-----------|---------|---------------------------|
| compute_user_metrics | Hourly | Aggregate page views, time spent | High volume - 50k+ events/hour |
| aggregate_school_metrics | Daily 2AM | Roll up to school level | Depends on user metrics |
| refresh_sdms_cache | Daily 3AM | Refresh stale SDMS records | Background maintenance |
| cleanup_old_metrics | Weekly Sun 4AM | Purge old data | Housekeeping |

## 10.2 Task Schedule

| Task Class | Schedule | Cron Expression |
|------------|----------|-----------------|
| `compute_user_metrics` | Hourly at :15 | `15 * * * *` |
| `aggregate_school_metrics` | Daily 2:00 AM | `0 2 * * *` |
| `refresh_sdms_cache` | Daily 3:00 AM | `0 3 * * *` |
| `cleanup_old_metrics` | Sunday 4:00 AM | `0 4 * * 0` |

## 10.3 Task Logic: Compute User Metrics (Hourly)

This task handles **high-volume engagement metrics** that would cause write amplification if processed via event observers.

**Important**: Quiz/assignment submissions are handled by event observers in real-time. This task only processes page views, resource access, and forum activity.

```
TASK compute_user_metrics:

    1. DETERMINE TIME WINDOW
       ┌─────────────────────────────────────────────┐
       │ period_end = current hour (rounded down)    │
       │ period_start = period_end - 1 hour          │
       │                                             │
       │ week_start = Monday 00:00:00 this week      │
       │ week_end = Sunday 23:59:59 this week        │
       └─────────────────────────────────────────────┘

    2. FIND ACTIVE USER-COURSE PAIRS
       ┌─────────────────────────────────────────────┐
       │ SELECT DISTINCT userid, courseid            │
       │ FROM logstore_standard_log                  │
       │ WHERE timecreated IN [period_start, end)    │
       │   AND component NOT IN (mod_quiz, mod_assign)│
       │   AND eventname NOT LIKE '%submitted%'      │
       │                                             │
       │ Note: Excludes events handled by observers  │
       └─────────────────────────────────────────────┘

    3. FOR EACH user-course pair:
       ┌─────────────────────────────────────────────┐
       │ a. Compute engagement metrics               │
       │    - total_actions (count)                  │
       │    - active_days (distinct dates)           │
       │    - time_spent (session gap analysis)      │
       │                                             │
       │ b. Compute content metrics                  │
       │    - resources_viewed, pages_viewed         │
       │    - forum_views, forum_posts               │
       │                                             │
       │ c. Lookup existing record for this week     │
       │                                             │
       │ d. IF exists:                               │
       │      INCREMENT high-volume fields           │
       │      PRESERVE submission fields (from obs.) │
       │    ELSE:                                    │
       │      INSERT new record                      │
       └─────────────────────────────────────────────┘

    4. LOG RESULTS
       "Completed: {N} processed, {M} errors"
```

### Merge Strategy (Hybrid Data Sources)

```
┌─────────────────────────────────────────────────────────────────┐
│                    elby_user_metrics RECORD                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  FROM SCHEDULED TASK (hourly):        FROM EVENT OBSERVERS:     │
│  ┌─────────────────────────┐          ┌─────────────────────┐   │
│  │ total_actions      +=   │          │ quizzes_attempted   │   │
│  │ active_days        MAX  │          │ quizzes_avg_score   │   │
│  │ time_spent_seconds +=   │          │ assignments_submitted│  │
│  │ resources_viewed   +=   │          │ course_progress     │   │
│  │ pages_viewed       +=   │          │ activities_completed│   │
│  │ forum_views        +=   │          └─────────────────────┘   │
│  │ forum_posts        +=   │                                    │
│  │ last_access        MAX  │          Written in REAL-TIME      │
│  └─────────────────────────┘          (never overwritten by     │
│                                        scheduled task)          │
│  Incremented HOURLY                                             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## 10.4 Task Logic: Aggregate School Metrics (Daily)

```
TASK aggregate_school_metrics:

    1. GET ALL SCHOOLS WITH ACTIVE USERS
       schools = SELECT DISTINCT schoolid
                 FROM elby_sdms_users

    2. FOR EACH school:
       FOR EACH period_type IN [weekly, monthly]:

           a. Compute school aggregates (see Section 7.3)
           b. UPSERT into elby_school_metrics

    3. LOG: "Aggregated {N} schools"
```

## 10.5 Task Logic: Refresh SDMS Cache (Daily)

```
TASK refresh_sdms_cache:

    STALE_THRESHOLD = 7 days

    1. FIND STALE USER RECORDS
       stale_users = SELECT * FROM elby_sdms_users
                     WHERE last_synced < (now - STALE_THRESHOLD)
                     LIMIT 100  // Batch to avoid API overload

    2. FOR EACH user IN stale_users:
       sync_service.sync_user(user.userid, force=true)
       // Includes cascade to school if needed

    3. FIND STALE SCHOOL RECORDS
       stale_schools = SELECT * FROM elby_schools
                       WHERE last_synced < (now - STALE_THRESHOLD)

    4. FOR EACH school IN stale_schools:
       sync_service.sync_school(school.school_code, force=true)

    5. LOG: "Refreshed {N} users, {M} schools"
```

## 10.6 Task Logic: Cleanup Old Metrics (Weekly)

```
TASK cleanup_old_metrics:

    RETENTION_DAYS = 365  // Configurable

    1. DELETE FROM elby_user_metrics
       WHERE period_end < (now - RETENTION_DAYS)

    2. DELETE FROM elby_school_metrics
       WHERE period_end < (now - RETENTION_DAYS)

    3. DELETE FROM elby_sync_log
       WHERE timecreated < (now - 30 days)

    4. LOG: "Cleaned up {N} user metrics, {M} school metrics, {P} logs"
```
