# 4. System Architecture

## 4.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           RTB Moodle LMS                                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌──────────────┐     ┌──────────────────┐     ┌───────────────────┐   │
│  │   Dashboard  │────▶│  Analytics API   │────▶│  Cache Tables     │   │
│  │   Plugin UI  │     │  (Internal)      │     │  (elby_*)         │   │
│  └──────────────┘     └──────────────────┘     └───────────────────┘   │
│         │                                               ▲               │
│         │                                               │               │
│         ▼                                               │               │
│  ┌──────────────┐     ┌──────────────────┐     ┌───────────────────┐   │
│  │   Moodle     │     │  Scheduled       │     │  Moodle Core      │   │
│  │   Events     │────▶│  Tasks           │────▶│  Tables           │   │
│  └──────────────┘     └──────────────────┘     │  (mdl_*)          │   │
│                               │                └───────────────────┘   │
│                               │                                         │
└───────────────────────────────┼─────────────────────────────────────────┘
                                │
                                ▼
                       ┌──────────────────┐
                       │    SDMS API      │
                       │  (Read-only)     │
                       │                  │
                       │ • GET /student/  │
                       │ • GET /school/   │
                       │ • GET /staff/    │
                       └──────────────────┘
```

## 4.2 Component Overview

| Component | Responsibility | Technology |
|-----------|---------------|------------|
| Dashboard Plugin UI | Render reports, filters, exports | Mustache templates, Chart.js |
| Analytics API | Internal endpoints for dashboard | Moodle external functions |
| Cache Tables | Store SDMS data + computed metrics | MySQL/MariaDB |
| Scheduled Tasks | Compute metrics, sync SDMS | Moodle Task API |
| SDMS Client | HTTP client for SDMS API | Moodle curl wrapper |
| Event Observers | Trigger on-demand SDMS sync | Moodle Events API |

## 4.3 Data Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         SDMS SYNC FLOW                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  User Login ──▶ Event Fired ──▶ Check Cache ──┬──▶ Cache Hit ──▶ Done  │
│                                               │                         │
│                                               └──▶ Cache Miss ──▶       │
│                                                         │               │
│                     ┌───────────────────────────────────┘               │
│                     ▼                                                   │
│              Extract SDMS ID from                                       │
│              user profile field                                         │
│                     │                                                   │
│                     ▼                                                   │
│              Call SDMS API ──▶ Store in elby_sdms_users                 │
│                     │                                                   │
│                     ▼                                                   │
│              School code in response?                                   │
│                     │                                                   │
│              ┌──────┴──────┐                                            │
│              ▼             ▼                                            │
│           Yes            No                                             │
│              │             │                                            │
│              ▼             ▼                                            │
│       Check elby_schools   Done                                         │
│              │                                                          │
│       ┌──────┴──────┐                                                   │
│       ▼             ▼                                                   │
│    Exists      Missing                                                  │
│       │             │                                                   │
│       ▼             ▼                                                   │
│     Done      Call SDMS /school/ ──▶ Store in elby_schools              │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│                      METRICS COMPUTATION FLOW                           │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Scheduled Task (Hourly/Daily)                                          │
│         │                                                               │
│         ▼                                                               │
│  Query mdl_logstore_standard_log                                        │
│  for previous period                                                    │
│         │                                                               │
│         ▼                                                               │
│  Aggregate by user + course                                             │
│         │                                                               │
│         ▼                                                               │
│  INSERT/UPDATE elby_user_metrics                                        │
│         │                                                               │
│         ▼                                                               │
│  Aggregate by school                                                    │
│         │                                                               │
│         ▼                                                               │
│  INSERT/UPDATE elby_school_metrics                                      │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```
