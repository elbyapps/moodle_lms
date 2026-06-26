# 12. Migration & Rollout Plan

## 12.1 Phase 1: Infrastructure (Week 1-2)

| Task | Owner | Status |
|------|-------|--------|
| Create database tables via install.xml | Dev | Pending |
| Implement SDMS client class | Dev | Pending |
| Configure SDMS API credentials | DevOps | Pending |
| Set up sync logging | Dev | Pending |
| Unit tests for SDMS client | QA | Pending |

## 12.2 Phase 2: Sync & Metrics (Week 3-4)

| Task | Owner | Status |
|------|-------|--------|
| Implement sync service | Dev | Pending |
| Event observers for login/enrollment | Dev | Pending |
| Metrics calculator class | Dev | Pending |
| School aggregator class | Dev | Pending |
| Scheduled tasks | Dev | Pending |
| Integration tests | QA | Pending |

## 12.3 Phase 3: Dashboard (Week 5-6)

| Task | Owner | Status |
|------|-------|--------|
| External API functions | Dev | Pending |
| Dashboard templates (Mustache) | Frontend | Pending |
| Chart.js integration | Frontend | Pending |
| Export functionality | Dev | Pending |
| UAT with pilot schools | QA | Pending |

## 12.4 Phase 4: Rollout (Week 7-8)

| Task | Owner | Status |
|------|-------|--------|
| Production deployment | DevOps | Pending |
| Initial SDMS cache population | DevOps | Pending |
| User training documentation | PM | Pending |
| Monitoring & alerting setup | DevOps | Pending |
| Go-live | All | Pending |

## 12.5 Initial Data Population

Since SDMS has no listing endpoints, initial population strategy:

1. **Passive population**: As users log in, their data syncs automatically
2. **CSV bootstrap** (optional): Admin uploads list of SDMS IDs to pre-sync
3. **School seeding**: Manually enter known school codes to pre-fetch school data

### CLI Bulk Sync Tool

A command-line tool for pre-populating the cache from a CSV file of SDMS IDs.

**Usage:**
```
php local/rtbanalytics/cli/bulk_sync.php --file=path/to/ids.csv --type=student
php local/rtbanalytics/cli/bulk_sync.php --file=schools.csv --type=school
```

**Parameters:**

| Option | Description |
|--------|-------------|
| `--file`, `-f` | Path to CSV file (one ID per line) |
| `--type`, `-t` | Record type: `student`, `staff`, or `school` |
| `--help`, `-h` | Show usage information |

**Process Flow:**
```
1. Read CSV line by line
2. FOR EACH sdms_id:
   - IF type=school: sync_school(sdms_id)
   - IF type=student/staff:
       - Find Moodle user by idnumber
       - IF found: sync_user(user.id)
3. Report: "{N} synced, {M} errors"
```

**CSV Format:**
```
SCH001
SCH002
SCH003
```
