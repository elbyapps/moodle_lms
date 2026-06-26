# 6. SDMS Integration Strategy

## 6.1 Sync Strategy Overview

Given the API constraint of single-record lookups only, we implement an **opportunistic sync** pattern:

| Strategy | Trigger | Data Synced |
|----------|---------|-------------|
| **On-demand user sync** | User login, enrollment, profile view | User's SDMS record + their school |
| **Manual school sync** | Admin action, first user from school | School record |
| **Periodic refresh** | Scheduled task (weekly) | Stale records (>7 days) |
| **Bulk import fallback** | Admin upload | CSV of SDMS IDs to sync |

## 6.2 SDMS Client Interface

The SDMS client handles all communication with the external SDMS API.

### Interface Definition

| Method | Input | Output | Description |
|--------|-------|--------|-------------|
| `get_student(code)` | SDMS student code | Student object or null | Fetch student record |
| `get_school(code)` | School code | School object or null | Fetch school record |
| `get_staff(id)` | Staff ID | Staff object or null | Fetch staff record |

### API Endpoints

SDMS uses **IP whitelist** authentication — no auth header needed.

| Endpoint | Example URL |
|----------|-------------|
| Student lookup | `GET {base_url}/student?studentCode=110705200131` |
| Staff lookup | `GET {base_url}/staff?staffNumber=22061916042` |
| School lookup | `GET {base_url}/school?schoolCode=120805` |

### Request Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                      SDMS API Request                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. Build request URL:                                          │
│     {base_url}/student?studentCode={code}                       │
│     {base_url}/staff?staffNumber={id}                           │
│     {base_url}/school?schoolCode={code}                         │
│                                                                 │
│  2. No auth header (IP whitelist)                               │
│                                                                 │
│  3. Execute with retry logic:                                   │
│     ┌─────────────────────────────────────────────┐             │
│     │  FOR attempt = 1 to 3:                      │             │
│     │    response = HTTP GET(url)                 │             │
│     │    IF status = 200: return parsed JSON      │             │
│     │    IF status = 404: return null             │             │
│     │    IF status >= 500: wait(2^attempt), retry │             │
│     │    ELSE: throw error                        │             │
│     └─────────────────────────────────────────────┘             │
│                                                                 │
│  4. Log request to elby_sync_log:                               │
│     - endpoint, status, response_time, error                    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Configuration Settings

| Setting | Type | Description |
|---------|------|-------------|
| `sdms_api_url` | URL | Base URL for SDMS API |
| `sdms_timeout` | Integer | Request timeout in seconds (default: 30) |
| `sdms_cache_ttl` | Integer | Cache TTL in seconds (default: 604800 = 7 days) |

## 6.3 Sync Service Logic

The sync service coordinates SDMS data retrieval and local cache management.

### Link User (New SDMS Users)

Used when linking a new SDMS user to a Moodle account. Takes the SDMS code directly.

```
FUNCTION link_user(userid, sdms_code, user_type):

    1. FETCH FROM SDMS
       ┌─────────────────────────────────────────────┐
       │ IF user_type = "student":                   │
       │   data = sdms_client.get_student(sdms_code) │
       │ ELSE:                                       │
       │   data = sdms_client.get_staff(sdms_code)   │
       │                                             │
       │ IF data is null: RETURN false               │
       └─────────────────────────────────────────────┘

    2. CASCADE TO SCHOOL SYNC
       ┌─────────────────────────────────────────────┐
       │ school_code = extract from response         │
       │ IF school_code exists:                      │
       │   sync_school(school_code)                  │
       └─────────────────────────────────────────────┘

    3. UPSERT USER RECORD (transaction)
       ┌─────────────────────────────────────────────┐
       │ UPSERT elby_sdms_users (sdms_id=sdms_code) │
       │ IF user_type = "student":                   │
       │   UPSERT elby_students                      │
       │ ELSE:                                       │
       │   UPSERT elby_teachers                      │
       │   REPLACE elby_staff_subjects               │
       │ RETURN success                              │
       └─────────────────────────────────────────────┘
```

### Refresh User (Existing Linked Users)

Used when refreshing cached data for a user already linked to SDMS (e.g. on login).

```
FUNCTION refresh_user(userid, force = false):

    1. CHECK CACHE
       ┌─────────────────────────────────────────────┐
       │ existing = query elby_sdms_users by userid   │
       │ IF NOT existing: RETURN false (not linked)  │
       │ IF NOT force AND fresh: RETURN true (hit)   │
       └─────────────────────────────────────────────┘

    2. FETCH FROM SDMS (using stored sdms_id)
       ┌─────────────────────────────────────────────┐
       │ Read sdms_id, user_type from existing record│
       │ IF user_type = "student":                   │
       │   data = sdms_client.get_student(sdms_id)   │
       │ ELSE:                                       │
       │   data = sdms_client.get_staff(sdms_id)     │
       │                                             │
       │ IF data is null:                            │
       │   mark_sync_error(userid, "Not found")      │
       │   RETURN false                              │
       └─────────────────────────────────────────────┘

    3. CASCADE TO SCHOOL SYNC
       ┌─────────────────────────────────────────────┐
       │ IF data.school_code exists:                 │
       │   sync_school(data.school_code)             │
       └─────────────────────────────────────────────┘

    4. UPSERT USER RECORD (transaction)
       ┌─────────────────────────────────────────────┐
       │ UPDATE elby_sdms_users + type-specific table│
       │ RETURN success                              │
       └─────────────────────────────────────────────┘
```

### School Sync Process

```
FUNCTION sync_school(school_code, force = false):

    1. CHECK CACHE
       existing = query elby_schools by school_code
       IF existing AND NOT force AND fresh: RETURN

    2. FETCH FROM SDMS
       data = sdms_client.get_school(school_code)
       IF data is null: RETURN false

    3. UPSERT SCHOOL RECORD
       IF existing: UPDATE
       ELSE: INSERT

       Fields mapped:
       - school_name ← data.schoolName
       - school_code ← data.schoolCode (identifier)
       - region_code ← data.regionCode
       - is_active ← (data.isActive == "ACTIVE" ? 1 : 0)
       - school_status ← data.schoolStatus
       - academic_year ← data.academicYear
       - last_synced ← now()

    4. SYNC SCHOOL HIERARCHY
       FOR EACH level IN data.levels[]:
         UPSERT elby_levels (schoolid, sdms_level_id, level_name, level_desc)

         FOR EACH combination IN level.combinations[]:
           UPSERT elby_combinations (levelid, combination_code, combination_name, combination_desc)

           FOR EACH grade IN combination.grades[]:
             UPSERT elby_grades (combinationid, grade_code, grade_name)

             FOR EACH classGroup IN grade.classGroups[]:
               UPSERT elby_classgroups (gradeid, sdms_class_id, class_name)

       // Update has_tvet flag
       has_tvet = EXISTS(SELECT 1 FROM elby_levels WHERE schoolid=X AND level_name='TVET')
       UPDATE elby_schools SET has_tvet = has_tvet WHERE id = school.id
```

### Field Mapping: SDMS → Local Cache

**Student Records:**

| SDMS Field | Local Table | Local Field | Notes |
|------------|-------------|-------------|-------|
| studentNumber | elby_sdms_users | sdms_id | Primary identifier |
| schoolCode | elby_sdms_users | schoolid | Looked up via elby_schools.school_code → id |
| combinationCode | elby_students | program_code | Short code (e.g. "541") |
| combination description | elby_students | program | Full program name (e.g. "Software Development") |
| registrationDate | elby_students | registration_date | Converted to Unix timestamp |
| currentAcadmicYear | elby_sdms_users | academic_year | e.g. "2025/2026" |
| status | elby_sdms_users | sdms_status | CONTINUING, INACTIVE, etc. |

**Staff Records:**

| SDMS Field | Local Table | Local Field | Notes |
|------------|-------------|-------------|-------|
| staffId | elby_sdms_users | sdms_id | Primary identifier |
| schooCode (note typo) | elby_sdms_users | schoolid | Looked up via elby_schools.school_code → id |
| position | elby_teachers | position | Job title |
| status | elby_sdms_users | sdms_status | Employment status |
| specialities[] | elby_staff_subjects | (multiple rows) | One row per subject; fields: level_id, level_name, combination_code, combination_name, subject_code, subject_name, grade_code, grade_name, class_group |

## 6.4 Event Observers

The plugin uses event observers for two purposes:
1. **SDMS Sync**: Triggered on login/enrollment to populate cache
2. **Real-time Metrics**: Triggered on low-volume, high-value events (submissions, completions)

### Registered Events

| Event | Handler | Purpose | Async |
|-------|---------|---------|-------|
| `user_loggedin` | sync_user | Sync SDMS data on login | Yes (adhoc task) |
| `user_enrolment_created` | sync_user | Sync SDMS data on enrollment | Yes (adhoc task) |
| `attempt_submitted` (quiz) | update_metrics | Update quiz scores immediately | No (direct) |
| `assessable_submitted` (assign) | update_metrics | Update submission count | No (direct) |
| `course_completed` | update_metrics | Mark course 100% complete | No (direct) |
| `course_module_completion_updated` | update_metrics | Recalculate progress | No (direct) |

### Events NOT Observed (High Volume)

These events are **intentionally not observed** to avoid write amplification:

| Event | Reason | How Handled |
|-------|--------|-------------|
| `course_viewed` | Too frequent (every course entry) | Hourly scheduled task |
| `course_module_viewed` | Too frequent (every click) | Hourly scheduled task |
| `discussion_viewed` | Too frequent (forum browsing) | Hourly scheduled task |

### Observer Logic: Quiz Submission

```
ON quiz_attempt_submitted(event):

    userid = event.relateduserid
    courseid = event.courseid
    attempt = get_quiz_attempt(event.objectid)
    quiz = get_quiz(attempt.quiz_id)

    // Calculate score percentage
    IF quiz.sumgrades > 0 AND attempt.sumgrades NOT NULL:
        score = (attempt.sumgrades / quiz.sumgrades) * 100
    ELSE:
        score = NULL

    // Atomic update to elby_user_metrics
    increment_metric(userid, courseid, {
        quizzes_attempted: +1,
        quizzes_completed: +1 if finished else 0,
        quizzes_avg_score: running_average(score)
    })
```

### Observer Logic: Activity Completion

```
ON course_module_completion_updated(event):

    userid = event.relateduserid
    courseid = event.courseid

    // Recalculate progress from completion table
    completed = COUNT activities WHERE completionstate >= 1
    total = COUNT activities WHERE completion > 0
    progress = (completed / total) * 100

    // Update absolute values (not increments)
    update_metric(userid, courseid, {
        activities_completed: completed,
        activities_total: total,
        course_progress: progress
    })
```

### Metric Increment Logic (Atomic UPSERT)

```
FUNCTION increment_metric(userid, courseid, increments, score?, score_type?):

    period_start = monday_of_current_week()
    period_end = sunday_of_current_week()

    existing = SELECT FROM elby_user_metrics
               WHERE userid, courseid, period_start, period_type='weekly'

    IF existing:
        FOR each field, value IN increments:
            IF field IN [course_progress, activities_*]:
                // Absolute value
                existing.field = value
            ELSE:
                // Increment
                existing.field += value

        IF score AND score_type = 'quiz':
            // Running average calculation
            old_total = existing.quizzes_avg_score * existing.quizzes_completed
            new_avg = (old_total + score) / (existing.quizzes_completed + 1)
            existing.quizzes_avg_score = new_avg

        existing.last_access = now()
        UPDATE elby_user_metrics

    ELSE:
        INSERT new record with increments as initial values
```
