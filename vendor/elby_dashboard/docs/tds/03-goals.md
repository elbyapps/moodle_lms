# 3. Goals & Non-Goals

## Goals

| ID | Goal | Success Metric |
|----|------|----------------|
| G1 | Cache SDMS school data locally | 100% of schools with enrolled users cached |
| G2 | Link Moodle users to SDMS records | >95% of active users linked within 30 days |
| G3 | Pre-compute activity metrics | Dashboard loads in <2 seconds |
| G4 | School-level reporting | Aggregates available for all cached schools |
| G5 | Zero manual data entry | All SDMS data populated via API sync |

## Non-Goals

- **Real-time SDMS sync**: Not feasible without bulk endpoints
- **Write-back to SDMS**: Read-only integration
- **Historical backfill**: Only metrics from deployment date forward
- **Predictive analytics**: Deferred to Phase 2 (use Moodle's built-in Analytics API)
