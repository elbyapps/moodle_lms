# 8. API Specification

## 8.1 Completion & Course Report APIs (Implemented)

These endpoints serve the dashboard frontend via Moodle's external functions API.

### get_course_completion_stats

Returns completion statistics for a course including section and activity breakdown.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| courseid | int | Yes | Course ID |

**Returns:** Course completion rate, sections with activity-level completion.

### get_category_completion_stats

Returns aggregated completion stats for all courses in a category (including subcategories).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| categoryid | int | Yes | Category ID |

**Returns:** Overall completion rate, per-course breakdown with sections.

### get_course_report_by_school

Returns course report data grouped by school code with completion rates and grades per section.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| courseid | int | Yes | Course ID |

**Returns:** Per-school breakdown with section completion rates and average grades.

### get_all_courses_report

Returns report data for all courses grouped by school code.

**Parameters:** None

**Returns:** All courses with per-school section stats.

## 8.2 SDMS Integration APIs (Implemented)

These endpoints implement the **cache-first, sync-on-miss** pattern for SDMS data.
The SDMS API uses **IP whitelist** authentication (no auth header).

### SDMS API Endpoints (External)

| Endpoint | Example | Description |
|----------|---------|-------------|
| `GET {base}/student?studentCode={code}` | `/student?studentCode=110705200131` | Fetch student record |
| `GET {base}/staff?staffNumber={id}` | `/staff?staffNumber=22061916042` | Fetch staff record |
| `GET {base}/school?schoolCode={code}` | `/school?schoolCode=120805` | Fetch school with hierarchy |

### get_user_sdms_profile

Returns a user's SDMS profile from local cache. Triggers refresh if data is stale.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| userid | int | Yes | Moodle user ID |

**Returns:** `success, sdms_id, user_type, school_code, school_name, academic_year, sdms_status, program (students), position (staff), sync_status, last_synced`

**Capability:** `local/elby_dashboard:view`

### get_school_info

Returns school data with full hierarchy (levels → combinations → grades → classgroups) from cache. Triggers sync on miss or stale.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| school_code | string | Yes | SDMS school code |

**Returns:** School details + nested hierarchy.

**Capability:** `local/elby_dashboard:viewreports`

### lookup_sdms_user

**Live lookup** — fetches directly from SDMS API without caching. Also fetches the user's school to resolve hierarchy. Used to preview SDMS data before linking a user.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| sdms_code | string | Yes | Student number or staff number |
| user_type | string | Yes | `student` or `staff` |

**Returns:** Raw SDMS user data + school hierarchy. Includes student_data (program, classgroup) or staff_data (position, specialities).

**Capability:** `local/elby_dashboard:manage`

### link_user

Links a new SDMS user to a Moodle account. Fetches from SDMS, cascades to school sync, and creates cache records.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| userid | int | Yes | Moodle user ID |
| sdms_code | string | Yes | Student number or staff number |
| user_type | string | Yes | `student` or `staff` |

**Returns:** `success, error, userid, sdms_code, timestamp`

**Capability:** `local/elby_dashboard:manage`

### refresh_user

Force refreshes cached SDMS data for an existing linked user. Reads the stored `sdms_id` and re-fetches from SDMS.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| userid | int | Yes | Moodle user ID |

**Returns:** `success, error, userid, timestamp`

**Capability:** `local/elby_dashboard:manage`

### sync_school_now

Force syncs a school from SDMS (ignores cache freshness). Updates school record and full hierarchy.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| school_code | string | Yes | SDMS school code |

**Returns:** `success, error, school_code, timestamp`

**Capability:** `local/elby_dashboard:manage`

## 8.3 External Functions Registry

| Function Name | Class | Type | Capability |
|---------------|-------|------|------------|
| `local_elby_dashboard_get_course_completion_stats` | `completion` | read | `view` |
| `local_elby_dashboard_get_category_completion_stats` | `completion` | read | `view` |
| `local_elby_dashboard_get_course_report_by_school` | `course_report` | read | `viewreports` |
| `local_elby_dashboard_get_all_courses_report` | `course_report` | read | `viewreports` |
| `local_elby_dashboard_get_user_sdms_profile` | `sdms` | read | `view` |
| `local_elby_dashboard_get_school_info` | `sdms` | read | `viewreports` |
| `local_elby_dashboard_lookup_sdms_user` | `sdms` | read | `manage` |
| `local_elby_dashboard_link_user` | `sdms` | write | `manage` |
| `local_elby_dashboard_refresh_user` | `sdms` | write | `manage` |
| `local_elby_dashboard_sync_school_now` | `sdms` | write | `manage` |

**Web Service:** `elby_dashboard` (enabled by default, no user restriction)
