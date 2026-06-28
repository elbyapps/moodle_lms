# 9. Dashboard Views

## 9.1 View Hierarchy

```
Dashboard Home
├── School Directory
│   ├── Filter by Province/District
│   ├── School cards with KPIs
│   └── Search by name/code
│
├── School Detail View
│   ├── Header: School info from SDMS
│   ├── KPI Cards: Enrolled, Active, At-Risk, Avg Score
│   ├── Engagement Trend Chart (weekly)
│   ├── Course Breakdown Table
│   └── Actions: Export, Sync
│
├── Student List View
│   ├── Filter by Course, Status, Engagement Level
│   ├── Sortable table with metrics
│   ├── Drill-down to student profile
│   └── Export to CSV
│
└── Admin Panel
    ├── Sync Status & Logs
    ├── Manual Sync Triggers
    ├── Cache Statistics
    └── Task Schedule Status
```

## 9.2 Key Dashboard Components

### School KPI Card

| Metric | Source | Update Frequency |
|--------|--------|------------------|
| Total Enrolled | elby_school_metrics.total_enrolled | Daily |
| Active This Week | elby_school_metrics.total_active | Daily |
| At-Risk Students | elby_school_metrics.at_risk_count | Daily |
| Avg Quiz Score | elby_school_metrics.avg_quiz_score | Daily |
| Course Progress | elby_school_metrics.avg_course_progress | Daily |

### Engagement Distribution Chart

Visual breakdown of students into engagement tiers:
- **High** (Green): >70th percentile activity
- **Medium** (Yellow): 30th-70th percentile
- **Low** (Red): <30th percentile
- **Inactive** (Gray): No activity in period

### Student Metrics Table

| Column | Source | Sortable |
|--------|--------|----------|
| Student Name | mdl_user | Yes |
| SDMS ID | elby_sdms_users.sdms_id | Yes |
| Program | elby_students.program | Yes |
| Last Active | elby_user_metrics.last_access | Yes |
| Active Days | elby_user_metrics.active_days | Yes |
| Quiz Avg | elby_user_metrics.quizzes_avg_score | Yes |
| Progress | elby_user_metrics.course_progress | Yes |
| Status | Computed from last_access | Yes |
