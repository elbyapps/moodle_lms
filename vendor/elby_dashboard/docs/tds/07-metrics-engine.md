# 7. Metrics Computation Engine

## 7.1 Computation Strategy

The system uses a **hybrid approach** combining scheduled tasks and event observers to balance real-time responsiveness with database performance.

### Why Hybrid?

At RTB scale, writing to the database on every page view creates a **Write Amplification Problem**:
- 5,000 students x 10 page views = 50,000 INSERT/UPDATE operations in minutes
- Row locks compete with dashboard reads
- Risk of database slowdown during peak hours (8-9 AM)

### Strategy Matrix

| Metric Type | Volume | Strategy | Latency | Rationale |
|-------------|--------|----------|---------|-----------|
| Page views, resource access | **High** | Scheduled task (hourly) | ~1 hour | Avoid write amplification |
| Quiz submissions | Medium | Event observer | Real-time | Critical for teachers |
| Assignment submissions | Medium | Event observer | Real-time | Critical for teachers |
| Course completions | Low | Event observer | Real-time | Low volume, high value |
| Login/enrollment (SDMS sync) | Low | Event observer | Real-time | Already implemented |
| School aggregates | N/A | Scheduled task (daily) | ~24 hours | Computed from user metrics |

### Data Flow Diagram

```
HIGH-VOLUME EVENTS (views, clicks, navigation)
    │
    ▼
┌─────────────────────────┐
│  mdl_logstore_*         │ ◀── Moodle writes here automatically
└─────────────────────────┘
    │
    │ Scheduled Task (hourly)
    │ Aggregates previous hour
    ▼
┌─────────────────────────┐
│  elby_user_metrics      │
└─────────────────────────┘


LOW-VOLUME EVENTS (submissions, completions)
    │
    ▼
┌─────────────────────────┐
│  Event Observer         │
│  (quiz_submitted, etc.) │
└─────────────────────────┘
    │
    │ Atomic UPSERT (immediate)
    ▼
┌─────────────────────────┐
│  elby_user_metrics      │
└─────────────────────────┘


AGGREGATION
    │
┌───┴───┐
│       │
▼       ▼
elby_user_metrics ───▶ Scheduled Task (daily 2AM) ───▶ elby_school_metrics
```

### Performance Expectations

| Scenario | Without Hybrid | With Hybrid |
|----------|---------------|-------------|
| 5,000 users, 10 clicks each | 50,000 immediate writes | 50,000 log reads (hourly batch) |
| Peak hour DB load | High (write locks) | Normal (reads only) |
| Quiz score visibility | Real-time | Real-time |
| Page view counts | Real-time | ~1 hour delay |
| Dashboard response | Potentially slow | Consistent <2s |

## 7.2 Metrics Calculator

The metrics calculator computes user engagement data from Moodle's log store. It runs in the **hourly scheduled task** (not event observers) to avoid write amplification.

### Metrics Computed

| Category | Metric | Source | Computation |
|----------|--------|--------|-------------|
| **Engagement** | total_actions | logstore | COUNT all actions |
| | active_days | logstore | COUNT DISTINCT dates |
| | first_access | logstore | MIN(timecreated) |
| | last_access | logstore | MAX(timecreated) |
| | time_spent_seconds | logstore | Session gap analysis |
| **Content** | resources_viewed | logstore | COUNT where component='mod_resource' |
| | pages_viewed | logstore | COUNT where component='mod_page' |
| | files_downloaded | logstore | COUNT where action='download' |
| | forum_views | logstore | COUNT where component='mod_forum' AND action='viewed' |
| | forum_posts | logstore | COUNT where component='mod_forum' AND action='posted' |

### Time Estimation Algorithm

```
FUNCTION estimate_time_spent(userid, courseid, period_start, period_end):

    SESSION_TIMEOUT = 1800  // 30 minutes

    timestamps = SELECT timecreated FROM logstore
                 WHERE userid, courseid, period
                 ORDER BY timecreated ASC

    IF count(timestamps) < 2:
        RETURN 0

    total_time = 0
    prev_time = timestamps[0]

    FOR each time IN timestamps[1:]:
        gap = time - prev_time
        IF gap < SESSION_TIMEOUT:
            total_time += gap
        // ELSE: new session, don't count the gap
        prev_time = time

    RETURN total_time
```

### Content Metrics Component Mapping

```
┌─────────────────────────────────────────────────────────────────┐
│                   LOG COMPONENT MAPPING                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  mod_resource ──┬── action='viewed'  ──▶ resources_viewed++     │
│                 └── action='download' ──▶ files_downloaded++    │
│                                                                 │
│  mod_page ─────────────────────────────▶ pages_viewed++         │
│                                                                 │
│  mod_url ──────── action='viewed' ────▶ videos_started++        │
│                   (approximation)                               │
│                                                                 │
│  mod_forum ────┬── action='viewed'  ──▶ forum_views++           │
│                └── action IN          ──▶ forum_posts++         │
│                    ('created','posted')                         │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## 7.3 School Aggregator

The school aggregator runs **daily** to roll up user metrics into school-level summaries.

### Aggregation Process

```
FUNCTION compute_school_metrics(school_id, courseid?, period_start):

    1. AGGREGATE USER METRICS
       ┌─────────────────────────────────────────────────────┐
       │ SELECT from elby_user_metrics                       │
       │ JOIN elby_sdms_users (filter by school_id)          │
       │ WHERE period_start, period_type                     │
       │                                                     │
       │ Compute:                                            │
       │   - COUNT DISTINCT userid → total_active            │
       │   - AVG(total_actions) → avg_actions_per_student    │
       │   - AVG(active_days) → avg_active_days              │
       │   - AVG(time_spent/60) → avg_time_minutes           │
       │   - SUM(resources_viewed) → total_resource_views    │
       │   - AVG(quiz_avg_score) → avg_quiz_score            │
       │   - AVG(course_progress) → avg_course_progress      │
       └─────────────────────────────────────────────────────┘

    2. COUNT ENROLLED STUDENTS
       ┌─────────────────────────────────────────────────────┐
       │ SELECT COUNT DISTINCT userid                        │
       │ FROM elby_sdms_users                                │
       │ JOIN user_enrolments (active only)                  │
       │ WHERE school_id, user_type='student'                │
       │                                                     │
       │ Result: total_enrolled                              │
       │ Compute: total_inactive = enrolled - active         │
       └─────────────────────────────────────────────────────┘

    3. COMPUTE ENGAGEMENT DISTRIBUTION
       ┌─────────────────────────────────────────────────────┐
       │ Get all total_actions for school's students         │
       │ Sort ascending                                      │
       │                                                     │
       │ p30_threshold = value at 30th percentile            │
       │ p70_threshold = value at 70th percentile            │
       │                                                     │
       │ FOR each student:                                   │
       │   IF actions >= p70: high_engagement++              │
       │   ELIF actions <= p30: low_engagement++             │
       │   ELSE: medium_engagement++                         │
       └─────────────────────────────────────────────────────┘

    4. COUNT AT-RISK STUDENTS
       ┌─────────────────────────────────────────────────────┐
       │ SELECT COUNT students WHERE:                        │
       │   - Enrolled at this school                         │
       │   - No activity in logstore for last 7 days         │
       │                                                     │
       │ Uses LEFT JOIN + IS NULL pattern                    │
       └─────────────────────────────────────────────────────┘
```

### Engagement Distribution Visualization

```
                    Engagement Distribution
    ┌──────────────────────────────────────────────────┐
    │                                                  │
    │  LOW         MEDIUM              HIGH            │
    │  (<30th)     (30-70th)           (>70th)         │
    │                                                  │
    │  ████████    ████████████████    ████████████    │
    │  At-risk     Normal              Top performers  │
    │                                                  │
    │  Action: Intervention needed                     │
    │                                                  │
    └──────────────────────────────────────────────────┘
```

### Output Fields

| Field | Type | Description |
|-------|------|-------------|
| total_enrolled | int | All students enrolled at school |
| total_active | int | Students with >=1 action in period |
| total_inactive | int | Enrolled but no activity |
| avg_actions_per_student | decimal | Mean actions per student |
| avg_active_days | decimal | Mean days with activity |
| avg_time_spent_minutes | decimal | Mean estimated time |
| avg_quiz_score | decimal | Mean quiz percentage |
| avg_course_progress | decimal | Mean completion % |
| high_engagement_count | int | Students >70th percentile |
| medium_engagement_count | int | Students 30-70th percentile |
| low_engagement_count | int | Students <30th percentile |
| at_risk_count | int | No activity in 7 days |
