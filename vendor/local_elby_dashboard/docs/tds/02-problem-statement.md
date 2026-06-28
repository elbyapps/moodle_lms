# 2. Problem Statement

## Current State

RTB operates a Moodle LMS serving TVET institutions across Rwanda. The Student Data Management System (SDMS) is the authoritative source for student enrollment, school information, and staff records. Currently:

1. **No unified view**: Moodle activity data and SDMS demographic data exist in separate silos
2. **Performance issues**: Generating engagement reports requires expensive queries against `mdl_logstore_standard_log` (millions of rows)
3. **Limited SDMS API**: Only single-record lookups available — no bulk export or listing endpoints

## SDMS API Constraints

| Endpoint | Input | Output | Limitation |
|----------|-------|--------|------------|
| `/api/student/{code}` | SDMS student code | Student record | Single record only |
| `/api/school/{code}` | School code | School record | Single record only |
| `/api/staff/{id}` | Staff ID | Staff record | Single record only |

**No available endpoints for:**
- List all students
- List all schools
- List students by school
- Bulk export
