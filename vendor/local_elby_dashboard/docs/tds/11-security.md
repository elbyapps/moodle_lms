# 11. Security Considerations

## 11.1 Access Control

| Capability | Description | Default Roles |
|------------|-------------|---------------|
| local/rtbanalytics:viewreports | View school and student reports | Manager, Admin |
| local/rtbanalytics:viewownschool | View reports for own school only | Teacher, Head |
| local/rtbanalytics:managesync | Trigger manual SDMS sync | Admin |
| local/rtbanalytics:exportdata | Export reports to CSV | Manager, Admin |

## 11.2 Data Protection

| Concern | Mitigation |
|---------|------------|
| SDMS API credentials | Stored encrypted in Moodle config, never logged |
| Personal data in logs | sync_log stores entity IDs only, not PII |
| School data isolation | Users can only query schools they're associated with (unless admin) |
| SQL injection | All queries use parameterized statements via $DB API |
| Rate limiting SDMS | Client implements exponential backoff and request throttling |

## 11.3 SDMS API Security

**Admin Settings** (Site Administration → Plugins → Local plugins → RTB Analytics)

| Setting | Type | Validation | Description |
|---------|------|------------|-------------|
| `sdms_api_url` | Text | PARAM_URL | SDMS API base URL |
| `sdms_api_key` | Password (masked) | - | API authentication key (stored encrypted) |

**Security Notes:**
- API key stored using Moodle's encrypted config storage
- Key never written to logs or error messages
- All API requests use HTTPS only
