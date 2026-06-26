# 5. Database Schema

> **Implementation:** All tables are defined in `db/install.xml` (Moodle XMLDB format). Table names use the `elby_` prefix to stay within Moodle's 28-character limit.

## 5.1 Entity Relationship Diagram

```
mdl_user
  │
  ▼ (1:1 via userid)
┌──────────────────┐
│  elby_sdms_users │──────────────────────────────────┐
│──────────────────│                                   │
│ id (PK)          │                                   │
│ userid (FK→user) │                                   │
│ sdms_id (UNIQUE) │       ┌──────────────────┐        │
│ schoolid (FK)────│──────▶│  elby_schools    │        │
│ user_type        │       │──────────────────│        │
│ academic_year    │       │ id (PK)          │        │
│ sdms_status      │       │ school_code (UQ) │        │
│ sync_status      │       │ region_code      │        │
└──────┬───────────┘       │ school_name      │        │
       │                   │ is_active        │        │
       │                   │ school_status    │        │
  ┌────┴────┐              │ has_tvet         │        │
  │         │              └──────┬───────────┘        │
  ▼         ▼                     │                    │
┌────────┐ ┌────────────┐        ▼ (1:many)           │
│ elby_  │ │ elby_      │  ┌──────────────┐           │
│students│ │ teachers   │  │ elby_levels  │           │
│────────│ │────────────│  │──────────────│           │
│ id     │ │ id         │  │ id (PK)      │           │
│sdms_   │ │ sdms_      │  │ schoolid(FK) │           │
│userid  │ │ userid(FK) │  │ sdms_level_id│           │
│(FK,UQ) │ │ (FK,UQ)    │  │ level_name   │           │
│classid │ │ position   │  └──────┬───────┘           │
│(FK)────│─┼──────┐     │        │                    │
│program │ │      │     │        ▼ (1:many)           │
│prog_   │ │      │     │  ┌────────────────────┐     │
│code    │ │      │     │  │ elby_combinations  │     │
│regist_ │ │      ▼     │  │────────────────────│     │
│date    │ │ ┌────────┐ │  │ id (PK)            │     │
└────────┘ │ │ elby_  │ │  │ levelid (FK)       │     │
           │ │ staff_ │ │  │ combination_code   │     │
           │ │subjects│ │  │ combination_name   │     │
           │ │────────│ │  │ combination_desc   │     │
           │ │ id     │ │  └──────┬─────────────┘     │
           │ │teacher │ │        │                    │
           │ │id (FK) │ │        ▼ (1:many)           │
           │ │subject │ │  ┌──────────────┐           │
           │ │_code   │ │  │ elby_grades  │           │
           │ │subject │ │  │──────────────│           │
           │ │_name   │ │  │ id (PK)      │           │
           │ │...     │ │  │combinationid │           │
           │ └────────┘ │  │ grade_code   │           │
           └────────────┘  │ grade_name   │           │
                           └──────┬───────┘           │
                                  │                   │
                                  ▼ (1:many)          │
                           ┌────────────────┐         │
                           │elby_classgroups│         │
                           │────────────────│         │
                           │ id (PK)        │◀────────┘
                           │ gradeid (FK)   │  (elby_students.classid)
                           │ sdms_class_id  │
                           │ class_name     │
                           └────────────────┘

mdl_user + mdl_course
  │
  ▼ (per user/course/period)
┌────────────────────┐      ┌──────────────────────┐
│ elby_user_metrics  │      │ elby_school_metrics  │
│────────────────────│      │──────────────────────│
│ userid (FK→user)   │      │ schoolid (FK→schools)│
│ courseid (FK→course│      │ courseid (0=all)     │
│ period_start       │      │ period_start         │
│ period_type        │      │ period_type          │
│ total_actions      │      │ total_enrolled       │
│ active_days        │      │ total_active         │
│ time_spent_seconds │      │ avg_quiz_score       │
│ quiz/assign metrics│      │ completion_rate      │
│ ...                │      │ ...                  │
└────────────────────┘      └──────────────────────┘

┌──────────────────┐
│ elby_sync_log    │
│──────────────────│
│ sync_type        │
│ entity_id        │
│ userid           │
│ operation        │
│ response_code    │
│ error_message    │
└──────────────────┘
```

## 5.2 Table Summary

| # | Table | Chars | Purpose |
|---|-------|-------|---------|
| 1 | `elby_schools` | 13 | School metadata from SDMS |
| 2 | `elby_levels` | 11 | Education levels (PPR, PR, OL, AL, TVET) |
| 3 | `elby_combinations` | 18 | Programs/trades within a level |
| 4 | `elby_grades` | 11 | Year/level grades within a combination |
| 5 | `elby_classgroups` | 16 | Class sections within a grade |
| 6 | `elby_sdms_users` | 15 | Base SDMS user link (shared fields) |
| 7 | `elby_students` | 14 | Student-specific SDMS data |
| 8 | `elby_teachers` | 14 | Teacher-specific SDMS data |
| 9 | `elby_staff_subjects` | 20 | Teacher subject assignments |
| 10 | `elby_user_metrics` | 18 | Pre-computed user activity metrics |
| 11 | `elby_school_metrics` | 20 | Aggregated school-level metrics |
| 12 | `elby_sync_log` | 13 | SDMS sync audit log |

## 5.3 Table Definitions

### 5.3.1 elby_schools

Caches school information from SDMS API.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | Auto-increment |
| school_code | CHAR(50) | NO | UNIQUE | SDMS `schoolCode` — the primary identifier |
| region_code | CHAR(50) | YES | INDEX | SDMS `regionCode` (encodes province/district/sector) |
| school_name | CHAR(255) | NO | | SDMS `schoolName` |
| is_active | INT(1) | NO | | 1=ACTIVE, 0=inactive |
| school_status | CHAR(50) | YES | | Public, Government Aided, etc. |
| school_category | CHAR(50) | YES | | SDMS `schoolCategory` |
| academic_year | CHAR(20) | YES | | e.g. "2025/2026" |
| gps_long | NUMBER(10,6) | YES | | SDMS `gpsLong` |
| gps_lat | NUMBER(10,6) | YES | | SDMS `gpsLat` |
| establishment_date | INT(10) | YES | | Unix timestamp |
| has_tvet | INT(1) | NO | | 1 if school has a TVET level (computed on sync) |
| sync_status | INT(1) | NO | | 1=synced, 0=error |
| sync_error | TEXT | YES | | Last error message |
| last_synced | INT(10) | YES | | Unix timestamp |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

### 5.3.2 School Hierarchy Tables

The SDMS school structure is: **School → Level → Combination → Grade → ClassGroup**. Each is stored in a normalized table.

#### elby_levels

Education levels within a school (PPR, PR, OL, AL, TVET).

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| schoolid | INT(10) | NO | FK → elby_schools.id | |
| sdms_level_id | CHAR(10) | NO | | SDMS `levelId` (0, 1, 2, 3, 5) |
| level_name | CHAR(50) | NO | INDEX | PPR, PR, OL, AL, TVET |
| level_desc | CHAR(255) | YES | | SDMS level description |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

**Unique key:** `(schoolid, sdms_level_id)` — one entry per level per school.

#### elby_combinations

Programs/trades within a level (e.g. NIT, SOD, MCE under TVET).

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| levelid | INT(10) | NO | FK → elby_levels.id | |
| combination_code | CHAR(50) | NO | INDEX | SDMS `combinationCode` (e.g. "574", "541") |
| combination_name | CHAR(50) | NO | | Short name (NIT, SOD) |
| combination_desc | CHAR(255) | YES | | Full program name (e.g. "Software Development") |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

**Unique key:** `(levelid, combination_code)` — one entry per combination per level.

#### elby_grades

Year/level grades within a combination (e.g. Level 3, Level 4, SENIOR 5).

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| combinationid | INT(10) | NO | FK → elby_combinations.id | |
| grade_code | CHAR(50) | NO | | SDMS `gradeCode` (e.g. "5503", "5504") |
| grade_name | CHAR(100) | NO | | Level 3, Level 4, SENIOR 5, etc. |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

**Unique key:** `(combinationid, grade_code)` — one entry per grade per combination.

#### elby_classgroups

Class sections within a grade (e.g. "LEVEL3 NIT", "S5MCE").

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| gradeid | INT(10) | NO | FK → elby_grades.id | |
| sdms_class_id | CHAR(100) | NO | UNIQUE | SDMS `classGroupId` (UUID) |
| class_name | CHAR(100) | NO | | SDMS `classGroupName` |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

**Example hierarchy queries:**
- All TVET programs at a school: `JOIN elby_levels → elby_combinations WHERE level_name='TVET'`
- All classes for SOD: `JOIN elby_combinations → elby_grades → elby_classgroups WHERE combination_name='SOD'`
- Is school TVET?: `SELECT 1 FROM elby_levels WHERE schoolid=X AND level_name='TVET'`

### 5.3.3 elby_sdms_users

Base table linking Moodle users to SDMS records. Contains shared fields for all user types.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| userid | INT(10) | NO | FK-UNIQUE → user.id | One Moodle user = one SDMS record |
| sdms_id | CHAR(50) | NO | UNIQUE | `studentNumber` (students) or `staffId` (staff) |
| schoolid | INT(10) | YES | FK → elby_schools.id | |
| user_type | CHAR(20) | NO | INDEX | student, teacher, admin, headmaster |
| academic_year | CHAR(20) | YES | | e.g. "2025/2026" |
| sdms_status | CHAR(50) | YES | INDEX | CONTINUING, INACTIVE, etc. |
| sync_status | INT(1) | NO | | 1=synced, 0=error |
| sync_error | TEXT | YES | | |
| last_synced | INT(10) | YES | | Unix timestamp |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

**Design notes:**
- PII fields (`national_id`, `date_of_birth`, `gender`) are intentionally omitted — not needed for analytics, available in SDMS if ever required.
- **API quirk:** Staff endpoint uses `schooCode` (typo, missing 'l') while student endpoint uses `schoolCode`. Sync code must handle both.

### 5.3.4 elby_students

Student-specific SDMS data. One-to-one with `elby_sdms_users`.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| sdms_userid | INT(10) | NO | FK-UNIQUE → elby_sdms_users.id | 1:1 |
| classid | INT(10) | YES | FK → elby_classgroups.id | Student's current class group |
| program | CHAR(255) | YES | | Combination description (e.g. "Software Development") |
| program_code | CHAR(50) | YES | INDEX | SDMS `combinationCode` (e.g. "541") |
| registration_date | INT(10) | YES | | Unix timestamp of SDMS `registrationDate` |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

### 5.3.5 elby_teachers

Teacher-specific SDMS data. One-to-one with `elby_sdms_users`.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| sdms_userid | INT(10) | NO | FK-UNIQUE → elby_sdms_users.id | 1:1 |
| position | CHAR(100) | YES | | e.g. "Class Teacher", "Head of Department" |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

### 5.3.6 elby_staff_subjects

Teacher subject/speciality assignments from SDMS. One row per subject a teacher teaches. Sourced from SDMS `specialities[]` array.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| teacherid | INT(10) | NO | FK → elby_teachers.id | |
| level_id | CHAR(10) | NO | | SDMS `levelId` |
| level_name | CHAR(50) | NO | INDEX | e.g. "TVET" |
| combination_code | CHAR(50) | NO | INDEX | SDMS `combinationCode` |
| combination_name | CHAR(255) | NO | | e.g. "Software Development" |
| subject_code | CHAR(50) | NO | | SDMS `subjectCode` (e.g. "SWDWD301") |
| subject_name | CHAR(255) | NO | | e.g. "Website Development" |
| grade_code | CHAR(50) | YES | | Nullable |
| grade_name | CHAR(100) | YES | | Nullable |
| class_group | CHAR(100) | YES | | SDMS `classGroup` name |
| timecreated | INT(10) | NO | | |
| timemodified | INT(10) | NO | | |

**Unique key:** `(teacherid, subject_code)` — a teacher teaches each subject once.

### 5.3.7 elby_user_metrics

Pre-computed activity metrics per user per course per period.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| userid | INT(10) | NO | compound | FK → mdl_user.id |
| courseid | INT(10) | NO | compound | FK → mdl_course.id |
| period_start | INT(10) | NO | compound | Unix timestamp |
| period_end | INT(10) | NO | | Unix timestamp |
| period_type | CHAR(20) | NO | compound | weekly (default), monthly |
| total_actions | INT(10) | NO | | Default 0 |
| active_days | INT(3) | NO | | Default 0 |
| first_access | INT(10) | YES | | |
| last_access | INT(10) | YES | | |
| time_spent_seconds | INT(10) | NO | | Default 0 |
| resources_viewed | INT(10) | NO | | Default 0 |
| resources_unique | INT(10) | NO | | Default 0 |
| videos_started | INT(10) | NO | | Default 0 |
| videos_completed | INT(10) | NO | | Default 0 |
| pages_viewed | INT(10) | NO | | Default 0 |
| files_downloaded | INT(10) | NO | | Default 0 |
| forum_views | INT(10) | NO | | Default 0 |
| forum_posts | INT(10) | NO | | Default 0 |
| forum_replies | INT(10) | NO | | Default 0 |
| chat_messages | INT(10) | NO | | Default 0 |
| assignments_viewed | INT(10) | NO | | Default 0 |
| assignments_submitted | INT(10) | NO | | Default 0 |
| assignments_graded | INT(10) | NO | | Default 0 |
| assignments_avg_score | NUMBER(5,2) | YES | | |
| quizzes_attempted | INT(10) | NO | | Default 0 |
| quizzes_completed | INT(10) | NO | | Default 0 |
| quizzes_avg_score | NUMBER(5,2) | YES | | |
| quizzes_avg_duration | INT(10) | YES | | Seconds |
| activities_completed | INT(10) | NO | | Default 0 |
| activities_total | INT(10) | NO | | Default 0 |
| course_progress | NUMBER(5,2) | YES | | 0-100 percentage |
| timecreated | INT(10) | NO | | |

**Compound unique key:** `(userid, courseid, period_start, period_type)`

**Note:** Only `weekly` records are created by the scheduled task. `period_type` is kept for future monthly rollup support.

### 5.3.8 elby_school_metrics

Aggregated school-level metrics for fast reporting.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| schoolid | INT(10) | NO | compound | FK → elby_schools.id |
| courseid | INT(10) | NO | compound | 0 = school-wide aggregate |
| period_start | INT(10) | NO | compound | |
| period_end | INT(10) | NO | | |
| period_type | CHAR(20) | NO | compound | weekly, monthly |
| total_enrolled | INT(10) | NO | | Default 0 |
| total_active | INT(10) | NO | | Default 0 |
| total_inactive | INT(10) | NO | | Default 0 |
| new_enrollments | INT(10) | NO | | Default 0 |
| avg_actions_per_student | NUMBER(10,2) | YES | | |
| avg_active_days | NUMBER(5,2) | YES | | |
| avg_time_spent_minutes | NUMBER(10,2) | YES | | |
| total_resource_views | INT(10) | NO | | Default 0 |
| avg_resources_per_student | NUMBER(10,2) | YES | | |
| total_submissions | INT(10) | NO | | Default 0 |
| total_quiz_attempts | INT(10) | NO | | Default 0 |
| avg_assignment_score | NUMBER(5,2) | YES | | |
| avg_quiz_score | NUMBER(5,2) | YES | | |
| submission_rate | NUMBER(5,2) | YES | | Percentage |
| avg_course_progress | NUMBER(5,2) | YES | | |
| completion_rate | NUMBER(5,2) | YES | | Percentage |
| high_engagement_count | INT(10) | NO | | Default 0, >70th percentile |
| medium_engagement_count | INT(10) | NO | | Default 0 |
| low_engagement_count | INT(10) | NO | | Default 0, <30th percentile |
| at_risk_count | INT(10) | NO | | Default 0, no activity >7 days |
| timecreated | INT(10) | NO | | |

**Compound unique key:** `(schoolid, courseid, period_start, period_type)`

**Note:** `courseid` uses `0` (not NULL) for school-wide aggregates to allow proper unique constraint enforcement.

### 5.3.9 elby_sync_log

Audit log for SDMS sync operations.

| Column | Type | Null | Key | Notes |
|--------|------|------|-----|-------|
| id | INT(10) | NO | PK | |
| sync_type | CHAR(50) | NO | INDEX | student, school, staff, metrics |
| entity_id | CHAR(50) | YES | INDEX | SDMS ID or userid |
| userid | INT(10) | YES | INDEX | Moodle user ID (if applicable) |
| operation | CHAR(20) | NO | INDEX | create, update, skip, error |
| request_url | CHAR(500) | YES | | |
| response_code | INT(5) | YES | | |
| response_time_ms | INT(10) | YES | | |
| error_message | TEXT | YES | | |
| details | TEXT | YES | | JSON for debugging |
| triggered_by | CHAR(50) | YES | | event, task, manual |
| timecreated | INT(10) | NO | INDEX | |
